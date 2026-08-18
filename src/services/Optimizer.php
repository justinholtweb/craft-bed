<?php

namespace justinholtweb\bed\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\web\View;
use justinholtweb\bed\helpers\Html;
use justinholtweb\bed\helpers\Runtime;
use justinholtweb\bed\models\EmbedNode;
use justinholtweb\bed\models\Provider;
use justinholtweb\bed\models\ScanResult;
use justinholtweb\bed\models\Settings;
use justinholtweb\bed\Plugin;

/**
 * The rewrite.
 *
 * HTML in, HTML out, and everything Bed did not mean to change comes out byte for byte identical.
 * That is a property of how this works rather than an aspiration: the scanner reports offsets,
 * every change is expressed as an edit to a byte range, and the edits are spliced back to front
 * so no offset moves before it has been used.
 *
 * Two modes. In **document** mode it is filtering a whole prepared response, so it splices its
 * own head and body additions into the string. In **fragment** mode it is being called from a
 * template by the `|bed` filter, so those additions go through the view and land in the right
 * place by themselves.
 */
class Optimizer extends Component
{
    public const MODE_DOCUMENT = 'document';
    public const MODE_FRAGMENT = 'fragment';

    /**
     * Whether a collector has already been put on this page by the `|bed` filter.
     *
     * `registerHtml()` has no key to dedupe on, so the service keeps the flag. It only covers one
     * request, which is exactly as long as it needs to.
     */
    private bool $collectorRegistered = false;

    /**
     * @param array{mode?: string, siteId?: int, uri?: string} $config
     */
    public function optimize(string $html, array $config = []): string
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        if (!$settings->enabled || trim($html) === '') {
            return $html;
        }

        $mode = $config['mode'] ?? self::MODE_FRAGMENT;
        $siteId = (int)($config['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id);
        $uri = $this->normalizeUri((string)($config['uri'] ?? ''));

        $scan = $plugin->scanner->scan($html);

        if ($scan->isEmpty()) {
            return $html;
        }

        // A page can pass through here twice — the `|bed` filter on a field, then the response
        // filter on the whole document. The embeds themselves are safe either way, because the
        // scanner treats a `data-bed` wrapper as opted out, but the page additions are not: two
        // stylesheets and two collectors would be a bug the second pass introduced.
        $hasStyle = str_contains($html, 'id="bed-css"');
        $hasCollector = str_contains($html, 'id="bed-collect"');

        foreach ($scan->nodes as $node) {
            $node->slotKey = substr(sha1($uri . '|' . $node->position . '|' . $node->embedKey), 0, 40);
        }

        $reservations = $plugin->metrics->reservations($siteId, $uri, $scan->nodes);

        // Deferring a loader script and putting a facade in front of a player both hand the job
        // of finishing the embed to the runtime. If the runtime is switched off, or the asset
        // manager could not publish it, neither is a deferral — it is a removal. So the capability
        // is decided once, here, and everything downstream asks this rather than the setting.
        $runtimeAvailable = $settings->registerJs && Runtime::scriptUrl() !== null;
        $canDefer = $settings->deferScripts && $runtimeAvailable;

        $edits = [];
        $rules = [];
        $eagerProviders = [];
        $lazyProviders = [];
        $wanted = [];

        $scriptForProvider = [];

        foreach ($scan->scripts as $script) {
            $scriptForProvider[$script['provider']] ??= $script['src'];
        }

        foreach ($scan->nodes as $node) {
            $reservation = $reservations[$node->slotKey] ?? ['heights' => [], 'aboveFold' => false, 'full' => [], 'samples' => 0];
            $eager = $reservation['aboveFold'] || $node->position < $settings->eagerCount;

            if ($eager) {
                $eagerProviders[$node->provider->handle] = $node->provider;
            } else {
                $lazyProviders[$node->provider->handle] ??= $node->provider;
            }

            $edits = array_merge($edits, $this->editsFor($node, $reservation, $eager, $settings, $canDefer ? $scriptForProvider : [], $runtimeAvailable));

            $rule = $this->cssFor($node, $reservation, $settings);

            if ($rule !== '') {
                $rules[] = $rule;
            }

            if ($settings->collectMetrics) {
                $wanted[$node->slotKey] = array_values($reservation['full']);
            }
        }

        if ($canDefer) {
            foreach ($scan->scripts as $script) {
                $edits[] = [$script['start'], $script['end'], ''];
            }
        }

        $html = Html::splice($html, $edits);

        // The collector is only worth shipping while some slot at some width still has something
        // to learn. A page Bed already understands sends nothing and asks for nothing.
        $collector = $this->collector($siteId, $uri, $scan, $wanted, $settings);

        if ($hasCollector) {
            $collector = null;
        }

        $head = $this->head($eagerProviders, $lazyProviders, $rules, $settings, $hasStyle);
        $foot = $this->foot($collector, $runtimeAvailable && !str_contains($html, (string)Runtime::scriptUrl()));

        return $mode === self::MODE_DOCUMENT
            ? $this->injectDocument($html, $head, $foot)
            : $this->injectFragment($html, $collector, $rules, $eagerProviders, $lazyProviders, $settings, $runtimeAvailable);
    }

    // ------------------------------------------------------------------ per-embed edits

    /**
     * @param array{heights: array<int, int>, aboveFold: bool, full: int[], samples: int} $reservation
     * @param array<string, string> $scriptForProvider
     * @return array<int, array{0: int, 1: int, 2: string}>
     */
    private function editsFor(EmbedNode $node, array $reservation, bool $eager, Settings $settings, array $scriptForProvider, bool $runtimeAvailable): array
    {
        $facade = $this->facadeFor($node, $settings, $runtimeAvailable);

        if ($facade !== null) {
            // One edit covering the whole embed, because a facade replaces it rather than dressing
            // it: the frame comes back out of a `<template>` when somebody clicks.
            return [[$node->start, $node->end, $facade]];
        }

        $edits = [];
        $wrapper = $this->wrapper($node, $reservation, $settings, $scriptForProvider);

        if ($wrapper !== null) {
            $edits[] = [$node->start, $node->start, $wrapper];
            $edits[] = [$node->end, $node->end, '</div>'];
        }

        $tag = $this->rewriteTag($node, $reservation, $eager, $settings);

        if ($tag !== null) {
            $edits[] = [$node->tagStart, $node->tagEnd, $tag];
        }

        return $edits;
    }

    /**
     * The bed itself.
     *
     * Returns null when there is nothing for it to hold — an embed with no known ratio, no
     * measurement and no provider fallback gets no wrapper at all, because an empty div around
     * somebody's markup is a change with no benefit attached to it.
     *
     * @param array{heights: array<int, int>, aboveFold: bool, full: int[], samples: int} $reservation
     * @param array<string, string> $scriptForProvider
     */
    private function wrapper(EmbedNode $node, array $reservation, Settings $settings, array $scriptForProvider): ?string
    {
        $ratio = $settings->reserveSpace ? $node->ratio($settings->useIntrinsicRatio) : null;
        $script = null;

        if ($node->isScriptEmbed()) {
            $script = $scriptForProvider[$node->provider->handle] ?? null;
        }

        $reserves = $settings->reserveSpace
            && ($ratio !== null || $reservation['heights'] !== [] || $node->provider->fallbackHeight > 0);

        if (!$reserves && $script === null) {
            return null;
        }

        $attrs = [
            'class' => $this->wrapperClass($node, $ratio !== null),
            'data-bed' => $node->slotKey,
        ];

        if ($ratio !== null) {
            $attrs['style'] = '--bed-ar:' . $ratio[0] . '/' . $ratio[1];
        }

        if ($script !== null) {
            $attrs['data-bed-script'] = $script;
            $attrs['data-bed-margin'] = $settings->scriptRootMargin;
        }

        return Html::renderStartTag('div', $attrs);
    }

    /**
     * The bed's class list.
     *
     * Deduped, because a `<video>` has both the handle and the kind `media` and emitting
     * `bed--media bed--media` is the kind of thing somebody has to read twice.
     */
    private function wrapperClass(EmbedNode $node, bool $hasRatio): string
    {
        $classes = ['bed', 'bed--' . $node->provider->handle, 'bed--' . $node->kind];

        if ($hasRatio) {
            $classes[] = 'bed--ratio';
        }

        return implode(' ', array_unique($classes));
    }

    /**
     * The embed's own opening tag, rewritten — or null if nothing about it needed to change.
     *
     * @param array{heights: array<int, int>, aboveFold: bool, full: int[], samples: int} $reservation
     */
    private function rewriteTag(EmbedNode $node, array $reservation, bool $eager, Settings $settings): ?string
    {
        $attrs = $node->attrs;
        $before = $attrs;

        if ($node->isFrame()) {
            if ($settings->lazyLoad) {
                if ($reservation['aboveFold']) {
                    // Measured inside the first screenful. An author's `loading="lazy"` is removed
                    // here and only here — a positional guess is not enough to overrule them, but
                    // twenty page views saying the element is on screen at load is.
                    if (strtolower($attrs['loading'] ?? '') === 'lazy') {
                        unset($attrs['loading']);
                    }
                } elseif (!$eager && !isset($attrs['loading'])) {
                    $attrs['loading'] = 'lazy';
                }
            }

            if ($settings->addTitles && trim($attrs['title'] ?? '') === '') {
                $attrs['title'] = $this->titleFor($node);
            }

            if ($settings->referrerPolicy !== '' && !isset($attrs['referrerpolicy'])) {
                $attrs['referrerpolicy'] = $settings->referrerPolicy;
            }
        }

        if ($node->isMedia()) {
            if ($settings->lazyLoad && !$eager && !isset($attrs['preload'])) {
                // `preload="none"` is the `loading="lazy"` of media. Without it a browser is
                // entitled to pull metadata — and Safari has historically pulled rather more than
                // that — for a video nobody has scrolled to.
                $attrs['preload'] = 'none';
            }
        }

        if ($attrs === $before) {
            return null;
        }

        return Html::renderStartTag($node->tagName, $attrs);
    }

    private function titleFor(EmbedNode $node): string
    {
        if ($node->provider->handle === Providers::GENERIC) {
            return Craft::t('bed', 'Embedded content');
        }

        return Craft::t('bed', '{provider} embed', ['provider' => $node->provider->name]);
    }

    // ------------------------------------------------------------------ facades

    /**
     * A poster and a play button in place of a player, or null if this embed does not get one.
     *
     * The saving is not marginal. A YouTube player is a document, a few hundred kilobytes of
     * script and a handful of third-party connections, all spent before anybody has decided to
     * watch anything. A facade is one image.
     */
    private function facadeFor(EmbedNode $node, Settings $settings, bool $runtimeAvailable): ?string
    {
        if (!$node->isFrame() || !$node->provider->supportsFacade()) {
            return null;
        }

        if (!in_array($node->provider->handle, $settings->facadeProviders, true)) {
            return null;
        }

        if (!$runtimeAvailable) {
            // Without the runtime nothing would ever open it, and a facade that cannot be opened
            // is a broken embed rather than a fast one.
            return null;
        }

        $ratio = $node->ratio($settings->useIntrinsicRatio) ?? [16, 9];
        $poster = $settings->facadePosters ? $node->provider->poster($node->src) : null;

        $wrapperAttrs = [
            'class' => $this->wrapperClass($node, true) . ' bed--facade',
            'data-bed' => $node->slotKey,
            'style' => '--bed-ar:' . $ratio[0] . '/' . $ratio[1],
        ];

        if ($node->provider->activateParams !== '') {
            $wrapperAttrs['data-bed-activate'] = $node->provider->activateParams;
        }

        $label = Craft::t('bed', 'Play {provider} video', ['provider' => $node->provider->name]);

        $out = Html::renderStartTag('div', $wrapperAttrs);
        $out .= '<button type="button" class="bed-facade" data-bed-facade aria-label="' . Html::escapeAttr($label) . '">';

        if ($poster !== null) {
            $out .= '<img class="bed-poster" src="' . Html::escapeAttr($poster) . '" alt="" loading="lazy" decoding="async">';
        }

        $out .= '<span class="bed-play" aria-hidden="true"></span>';
        $out .= '</button>';

        // A `<template>` rather than an escaped blob in an attribute: its contents are inert, no
        // frame is created and nothing is fetched until the runtime clones it in.
        $out .= '<template data-bed-frame>' . $this->frameForFacade($node) . '</template>';

        return $out . '</div>';
    }

    /** The original frame, with any lazy attribute stripped — it is about to be wanted. */
    private function frameForFacade(EmbedNode $node): string
    {
        $attrs = $node->attrs;
        unset($attrs['loading']);

        if (trim($attrs['title'] ?? '') === '') {
            $attrs['title'] = $this->titleFor($node);
        }

        return Html::renderStartTag('iframe', $attrs) . '</iframe>';
    }

    // ------------------------------------------------------------------ css

    /**
     * The `min-height` rules for one slot, as a media-query ladder.
     *
     * These live in a stylesheet and not in the wrapper's `style` attribute for a reason that
     * costs an hour to find: an inline style beats a media query, so the moment a fallback height
     * is written inline, every measured height for every breakpoint stops applying.
     *
     * @param array{heights: array<int, int>, aboveFold: bool, full: int[], samples: int} $reservation
     */
    private function cssFor(EmbedNode $node, array $reservation, Settings $settings): string
    {
        if (!$settings->reserveSpace) {
            return '';
        }

        // A frame with a real ratio holds its own space; a min-height on top of that would only
        // ever make the box too tall.
        if ($node->ratio($settings->useIntrinsicRatio) !== null) {
            return '';
        }

        $selector = '[data-bed="' . $node->slotKey . '"]';
        $css = '';

        if ($node->provider->fallbackHeight > 0) {
            $css .= $selector . '{--bed-min:' . $node->provider->fallbackHeight . 'px}';
        }

        foreach ($reservation['heights'] as $breakpoint => $height) {
            $css .= self::mediaQuery((int)$breakpoint) . '{' . $selector . '{--bed-min:' . (int)$height . 'px}}';
        }

        return $css;
    }

    /** The media query that selects exactly the viewport widths in one bucket. */
    public static function mediaQuery(int $breakpoint): string
    {
        $breakpoints = Settings::BREAKPOINTS;
        $index = array_search($breakpoint, $breakpoints, true);

        if ($index === false) {
            return '@media all';
        }

        $lower = $index > 0 ? $breakpoints[$index - 1] + 1 : 0;
        $isTop = $index === count($breakpoints) - 1;

        if ($lower === 0) {
            return '@media (max-width:' . $breakpoint . 'px)';
        }

        // The top bucket catches everything above the second-widest, so it has no upper bound.
        return $isTop
            ? '@media (min-width:' . $lower . 'px)'
            : '@media (min-width:' . $lower . 'px) and (max-width:' . $breakpoint . 'px)';
    }

    // ------------------------------------------------------------------ page additions

    /**
     * @param array<string, int[]> $wanted
     */
    private function collector(int $siteId, string $uri, ScanResult $scan, array $wanted, Settings $settings): ?string
    {
        if (!$settings->collectMetrics || $wanted === []) {
            return null;
        }

        // Every bucket satisfied for every slot means there is nothing left to ask.
        $outstanding = false;

        foreach ($wanted as $full) {
            if (count($full) < count(Settings::BREAKPOINTS)) {
                $outstanding = true;
                break;
            }
        }

        if (!$outstanding) {
            return null;
        }

        $payload = [
            'url' => \craft\helpers\UrlHelper::actionUrl('bed/metrics/collect'),
            'token' => Plugin::getInstance()->metrics->token($siteId, $uri, $scan->nodes),
            'rate' => $settings->sampleRate,
            'breakpoints' => Settings::BREAKPOINTS,
            'slots' => $wanted,
        ];

        return '<script type="application/json" id="bed-collect">' . $this->safeJson($payload) . '</script>';
    }

    /**
     * JSON that cannot end the `<script>` element it is sitting in.
     *
     * A URL in the payload containing the literal characters `</script` would otherwise close the
     * block early and spill the rest of the object into the page as text.
     */
    private function safeJson(array $payload): string
    {
        return Json::encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /**
     * @param array<string, Provider> $eagerProviders
     * @param array<string, Provider> $lazyProviders
     * @param string[] $rules
     */
    private function head(array $eagerProviders, array $lazyProviders, array $rules, Settings $settings, bool $hasStyle = false): string
    {
        $plugin = Plugin::getInstance();
        $out = '';

        $links = $plugin->hints->links(array_values($eagerProviders), array_values($lazyProviders));

        if ($links !== []) {
            $out .= $plugin->hints->render($links);
        }

        if ($settings->registerCss) {
            $css = $hasStyle ? '' : Runtime::css();
            $rulesCss = implode('', $rules);

            if ($css !== '' || $rulesCss !== '') {
                $out .= '<style' . ($hasStyle ? '' : ' id="bed-css"') . '>' . $css . $rulesCss . '</style>';
            }
        }

        return $out;
    }

    private function foot(?string $collector, bool $runtimeAvailable): string
    {
        $out = $collector ?? '';

        if ($runtimeAvailable) {
            $out .= '<script src="' . Html::escapeAttr((string)Runtime::scriptUrl()) . '" defer></script>';
        }

        return $out;
    }

    // ------------------------------------------------------------------ splicing the page

    private function injectDocument(string $html, string $head, string $foot): string
    {
        if ($head !== '') {
            $html = $this->afterHeadOpen($html, $head) ?? $html;
        }

        if ($foot !== '') {
            $html = $this->beforeBodyClose($html, $foot) ?? $html;
        }

        return $html;
    }

    /**
     * Puts markup immediately after the opening `<head>` tag.
     *
     * Immediately after, because a `preconnect` is only useful for as long as it is ahead of the
     * request it is warming up. A hint at the bottom of the head, behind three stylesheets, has
     * missed the point of being a hint.
     */
    private function afterHeadOpen(string $html, string $markup): ?string
    {
        if (!preg_match('/<head\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $at = $match[0][1] + strlen($match[0][0]);

        return substr($html, 0, $at) . $markup . substr($html, $at);
    }

    /**
     * Puts markup before the *last* `</body>`.
     *
     * The last one, because a page may legitimately contain the string earlier — in a code
     * sample, in an escaped snippet, in a `<textarea>` holding markup somebody is editing.
     */
    private function beforeBodyClose(string $html, string $markup): ?string
    {
        $at = strripos($html, '</body>');

        if ($at === false) {
            return null;
        }

        return substr($html, 0, $at) . $markup . substr($html, $at);
    }

    /**
     * @param array<string, Provider> $eagerProviders
     * @param array<string, Provider> $lazyProviders
     * @param string[] $rules
     */
    private function injectFragment(
        string $html,
        ?string $collector,
        array $rules,
        array $eagerProviders,
        array $lazyProviders,
        Settings $settings,
        bool $runtimeAvailable,
    ): string {
        $view = Craft::$app->getView();
        $plugin = Plugin::getInstance();

        foreach ($plugin->hints->links(array_values($eagerProviders), array_values($lazyProviders)) as $index => $link) {
            $view->registerLinkTag($link, 'bed-hint-' . $link['rel'] . '-' . $index . '-' . md5($link['href']));
        }

        if ($settings->registerCss) {
            $css = Runtime::css();

            if ($css !== '') {
                // Keyed, so a template calling `|bed` on six fields registers the base stylesheet
                // once rather than six times.
                $view->registerCss($css, ['id' => 'bed-css'], 'bed-css');
            }

            $rulesCss = implode('', $rules);

            if ($rulesCss !== '') {
                $view->registerCss($rulesCss, [], 'bed-rules-' . md5($rulesCss));
            }
        }

        if ($collector !== null && !$this->collectorRegistered) {
            $this->collectorRegistered = true;
            $view->registerHtml($collector, View::POS_END);
        }

        if ($runtimeAvailable) {
            $view->registerJsFile(Runtime::scriptUrl(), ['defer' => true, 'depends' => []]);
        }

        return $html;
    }

    // ------------------------------------------------------------------ misc

    /** The page identity a slot key is built on: a path, no host, no query, no trailing slash. */
    private function normalizeUri(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '') {
            return '/';
        }

        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : $uri;
        $path = '/' . trim($path, '/');

        return mb_substr($path, 0, 500);
    }
}
