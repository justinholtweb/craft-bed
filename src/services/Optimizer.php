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

    /** The same, for the consent island. */
    private bool $consentRegistered = false;

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
        $hasConsent = str_contains($html, 'id="bed-consent"');

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

        // Which providers on this page wait for consent. A fact about the site, never about the
        // visitor: every listed embed is held for everybody, and the runtime releases it in the
        // browser, so a page out of a full-page cache is right for whoever receives it.
        $gated = [];

        foreach ($scan->nodes as $node) {
            $category = $plugin->consent->categoryFor($node);

            if ($category !== null) {
                $gated[$node->provider->handle] = $category;
            }
        }

        $scriptForProvider = [];

        foreach ($scan->scripts as $script) {
            $scriptForProvider[$script['provider']] ??= $script['src'];
        }

        foreach ($scan->nodes as $node) {
            $reservation = $reservations[$node->slotKey] ?? ['heights' => [], 'aboveFold' => false, 'full' => [], 'samples' => 0];
            $eager = $reservation['aboveFold'] || $node->position < $settings->eagerCount;
            $category = $plugin->consent->categoryFor($node);

            if ($category !== null) {
                // No resource hint for a held embed: a `preconnect` is a connection to the third
                // party, and opening one before the visitor has agreed is the thing being held.
                $edits = array_merge($edits, $this->gatedEditsFor($html, $node, $category, $reservation, $eager, $settings, $runtimeAvailable ? $scriptForProvider : [], $runtimeAvailable));
            } else {
                if ($eager) {
                    $eagerProviders[$node->provider->handle] = $node->provider;
                } else {
                    $lazyProviders[$node->provider->handle] ??= $node->provider;
                }

                $edits = array_merge($edits, $this->editsFor($node, $reservation, $eager, $settings, $canDefer ? $scriptForProvider : [], $runtimeAvailable));
            }

            $rule = $this->cssFor($node, $reservation, $settings);

            if ($rule !== '') {
                $rules[] = $rule;
            }

            if ($settings->collectMetrics) {
                $wanted[$node->slotKey] = array_values($reservation['full']);
            }
        }

        foreach ($scan->scripts as $script) {
            // A held provider's loader script is lifted whatever `deferScripts` says, because left
            // in place it would run before anybody was asked. Without the runtime it is lifted all
            // the same and nothing brings it back: the embed's own fallback content stays, and the
            // notice says why. Consent fails closed.
            if ($canDefer || isset($gated[$script['provider']])) {
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

        $consent = $gated !== [] && $runtimeAvailable && !$hasConsent
            ? '<script type="application/json" id="bed-consent">' . $this->safeJson($plugin->consent->runtimeConfig()) . '</script>'
            : null;

        if ($mode !== self::MODE_DOCUMENT) {
            return $this->injectFragment($html, $collector, $rules, $eagerProviders, $lazyProviders, $settings, $runtimeAvailable, $consent);
        }

        $head = $this->head($eagerProviders, $lazyProviders, $rules, $settings, $hasStyle);
        $foot = $this->foot(($consent ?? '') . ($collector ?? ''), $runtimeAvailable && !str_contains($html, (string)Runtime::scriptUrl()));

        return $this->injectDocument($html, $head, $foot);
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
     * A held embed: the bed, a notice saying what is waiting and why, and the embed itself parked
     * where it cannot load anything.
     *
     * A frame or a player goes into a `<template>` — inert, so no request is made until the
     * runtime clones it out — and a facade goes in whole, poster and all, because the poster is a
     * third-party image too. A script embed's placeholder is the platform's own fallback (the
     * text of the post and a link to it) and loads nothing by itself, so it stays readable and
     * only its loader script waits.
     *
     * The markup is the same for every visitor. Whether *this* visitor has agreed is the
     * runtime's question, answered in the browser.
     *
     * @param array{heights: array<int, int>, aboveFold: bool, full: int[], samples: int} $reservation
     * @param array<string, string> $scriptForProvider
     * @return array<int, array{0: int, 1: int, 2: string}>
     */
    private function gatedEditsFor(string $html, EmbedNode $node, string $category, array $reservation, bool $eager, Settings $settings, array $scriptForProvider, bool $runtimeAvailable): array
    {
        $notice = $this->notice($node, $category, $settings, $runtimeAvailable);
        $state = ['data-bed-gate' => $category, 'data-bed-consent' => 'pending', 'data-bed-provider' => $node->provider->handle];

        if ($node->isScriptEmbed()) {
            $attrs = (array)$this->wrapperAttrs($node, $reservation, $settings, $scriptForProvider, true);
            $attrs['class'] .= ' bed--gated';

            return [
                [$node->start, $node->start, Html::renderStartTag('div', $attrs + $state) . $notice],
                [$node->end, $node->end, '</div>'],
            ];
        }

        if ($this->wantsFacade($node, $settings, $runtimeAvailable)) {
            [$attrs, $held] = $this->facadeParts($node, $settings);
        } else {
            $attrs = (array)$this->wrapperAttrs($node, $reservation, $settings, [], true);
            $tag = $this->rewriteTag($node, $reservation, $eager, $settings)
                ?? substr($html, $node->tagStart, $node->tagEnd - $node->tagStart);
            $held = $tag . substr($html, $node->tagEnd, $node->end - $node->tagEnd);
        }

        $attrs['class'] .= ' bed--gated';

        return [[
            $node->start,
            $node->end,
            Html::renderStartTag('div', $attrs + $state) . $notice . '<template data-bed-held>' . $held . '</template></div>',
        ]];
    }

    /**
     * What a reader sees in place of a held embed.
     *
     * The load button and the settings button need the runtime; the link does not, so a reader
     * without JavaScript can still get to the content at its source. The settings button is
     * hidden until the runtime finds a consent platform it knows how to reopen.
     */
    private function notice(EmbedNode $node, string $category, Settings $settings, bool $runtimeAvailable): string
    {
        $consent = Plugin::getInstance()->consent;
        $text = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $out = '<div class="bed-consent" data-bed-notice>';
        $out .= '<p class="bed-consent__text">' . $text($consent->message($node, $category)) . '</p>';
        $actions = '';

        if ($runtimeAvailable && $settings->consentClickToLoad) {
            // A script embed's loader renders every placeholder of its provider on the page at
            // once, so the button says so rather than pretending to load just this one.
            $label = $node->isScriptEmbed()
                ? Craft::t('bed', 'Load {provider} posts', ['provider' => $consent->providerName($node)])
                : Craft::t('bed', 'Load the embed');

            $actions .= '<button type="button" class="bed-consent__load" data-bed-load>' . $text($label) . '</button>';
        }

        if ($runtimeAvailable) {
            $actions .= '<button type="button" class="bed-consent__manage" data-bed-manage hidden>' . $text(Craft::t('bed', 'Cookie settings')) . '</button>';
        }

        $url = $node->isScriptEmbed() ? null : $node->sourceUrl();

        if ($url !== null) {
            $host = preg_replace('/^www\./i', '', (string)parse_url($url, PHP_URL_HOST));
            $actions .= '<a class="bed-consent__link" href="' . Html::escapeAttr($url) . '" target="_blank" rel="noopener noreferrer">'
                . $text(Craft::t('bed', 'Open on {host}', ['host' => $host])) . '</a>';
        }

        if ($actions !== '') {
            $out .= '<p class="bed-consent__actions">' . $actions . '</p>';
        }

        return $out . '</div>';
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
        $attrs = $this->wrapperAttrs($node, $reservation, $settings, $scriptForProvider, false);

        return $attrs !== null ? Html::renderStartTag('div', $attrs) : null;
    }

    /**
     * The bed's attributes, or null when there is nothing for it to hold and `$force` is off.
     *
     * @param array{heights: array<int, int>, aboveFold: bool, full: int[], samples: int} $reservation
     * @param array<string, string> $scriptForProvider
     * @return array<string, string>|null
     */
    private function wrapperAttrs(EmbedNode $node, array $reservation, Settings $settings, array $scriptForProvider, bool $force): ?array
    {
        $ratio = $settings->reserveSpace ? $node->ratio($settings->useIntrinsicRatio) : null;
        $script = null;

        if ($node->isScriptEmbed()) {
            $script = $scriptForProvider[$node->provider->handle] ?? null;
        }

        $reserves = $settings->reserveSpace
            && ($ratio !== null || $reservation['heights'] !== [] || $node->provider->fallbackHeight > 0);

        if (!$reserves && $script === null && !$force) {
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

        return $attrs;
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
        if (!$this->wantsFacade($node, $settings, $runtimeAvailable)) {
            return null;
        }

        [$wrapperAttrs, $inner] = $this->facadeParts($node, $settings);

        return Html::renderStartTag('div', $wrapperAttrs) . $inner . '</div>';
    }

    /**
     * A facade's wrapper attributes and its contents, separately, so a held embed can put the
     * contents behind a consent notice and keep the wrapper.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function facadeParts(EmbedNode $node, Settings $settings): array
    {
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

        $out = '<button type="button" class="bed-facade" data-bed-facade aria-label="' . Html::escapeAttr($label) . '">';

        if ($poster !== null) {
            $out .= '<img class="bed-poster" src="' . Html::escapeAttr($poster) . '" alt="" loading="lazy" decoding="async">';
        }

        $out .= '<span class="bed-play" aria-hidden="true"></span>';
        $out .= '</button>';

        // A `<template>` rather than an escaped blob in an attribute: its contents are inert, no
        // frame is created and nothing is fetched until the runtime clones it in.
        $out .= '<template data-bed-frame>' . $this->frameForFacade($node) . '</template>';

        return [$wrapperAttrs, $out];
    }

    private function wantsFacade(EmbedNode $node, Settings $settings, bool $runtimeAvailable): bool
    {
        if (!$node->isFrame() || !$node->provider->supportsFacade()) {
            return false;
        }

        if (!in_array($node->provider->handle, $settings->facadeProviders, true)) {
            return false;
        }

        // Without the runtime nothing would ever open it, and a facade that cannot be opened is a
        // broken embed rather than a fast one.
        return $runtimeAvailable;
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

    private function foot(string $islands, bool $runtimeAvailable): string
    {
        $out = $islands;

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
        ?string $consent = null,
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

        if ($consent !== null && !$this->consentRegistered) {
            $this->consentRegistered = true;
            $view->registerHtml($consent, View::POS_END);
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
