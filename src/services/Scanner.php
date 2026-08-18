<?php

namespace justinholtweb\bed\services;

use craft\base\Component;
use justinholtweb\bed\helpers\Html;
use justinholtweb\bed\models\EmbedNode;
use justinholtweb\bed\models\Provider;
use justinholtweb\bed\models\ScanResult;
use justinholtweb\bed\Plugin;

/**
 * Finds the embeds in a blob of HTML.
 *
 * Three shapes count as an embed:
 *
 *   1. an `<iframe>`, whoever it points at;
 *   2. a `<video>` or `<audio>` element;
 *   3. a placeholder element carrying a provider's marker class or marker attribute — the shape a
 *      tweet, a TikTok or an Instagram post arrives in, where the markup on the page is inert
 *      until a third-party script rewrites it.
 *
 * Nothing here rewrites anything. The scanner reports offsets and the optimiser decides.
 */
class Scanner extends Component
{
    /** Elements that can carry a provider's marker class. */
    private const MARKER_TAGS = ['blockquote', 'div', 'a', 'section', 'p', 'iframe'];

    /**
     * Any attribute that opts an element and everything under it out.
     *
     * `data-bed` is on Bed's own wrapper, so a page that has already been through the optimiser —
     * a cached fragment, a Twig filter run before the response filter — is not wrapped twice.
     */
    private const SKIP_ATTRS = ['data-bed', 'data-bed-skip'];

    /** @var string|null The precheck pattern, built once from the registry. */
    private ?string $needle = null;

    public function scan(string $html): ScanResult
    {
        $result = new ScanResult();

        if ($html === '' || !$this->mightContainEmbed($html)) {
            return $result;
        }

        $tokens = Html::tokenize($html);
        $providers = Plugin::getInstance()->providers;

        $nodes = [];
        $candidateScripts = [];
        $skipUntil = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token['type'] !== 'start') {
                continue;
            }

            // A loader script is collected wherever it is, including inside a region that is
            // otherwise being skipped, because whether it can be lifted is decided later.
            if ($token['name'] === 'script') {
                $script = $this->loaderScript($tokens, $i, $providers);

                if ($script !== null) {
                    $candidateScripts[] = $script;
                }

                continue;
            }

            if ($token['start'] < $skipUntil) {
                continue;
            }

            if ($this->isOptedOut($token['attrs'])) {
                $skipUntil = Html::closingOffset($tokens, $i) ?? $token['end'];
                continue;
            }

            $node = $this->nodeFor($tokens, $i, $providers);

            if ($node === null) {
                continue;
            }

            $nodes[] = $node;

            // Nothing inside an embed is another embed as far as Bed is concerned. A second bed
            // inside the first would reserve space twice for the same pixels.
            $skipUntil = $node->end;
        }

        foreach ($nodes as $position => $node) {
            $node->position = $position;
            $node->embedKey = $this->embedKey($node);
        }

        $result->nodes = $nodes;
        $result->scripts = $this->liftableScripts($candidateScripts, $nodes);

        return $result;
    }

    /**
     * A cheap look for anything worth tokenising.
     *
     * The response filter runs on every front-end page, and most pages on most sites have no
     * embed on them at all. One pass of a compiled pattern is a great deal less work than
     * tokenising a document to discover there was nothing in it.
     */
    private function mightContainEmbed(string $html): bool
    {
        if ($this->needle === null) {
            $needles = ['<iframe', '<video', '<audio'];

            foreach (Plugin::getInstance()->providers->all() as $provider) {
                foreach ([...$provider->markerClasses, ...$provider->markerAttrs] as $marker) {
                    $needles[] = $marker;
                }
            }

            $this->needle = '/' . implode('|', array_map(fn(string $n) => preg_quote($n, '/'), array_unique($needles))) . '/i';
        }

        return (bool)preg_match($this->needle, $html);
    }

    // ------------------------------------------------------------------ recognition

    /**
     * @param array<int, array<string, mixed>> $tokens
     */
    private function nodeFor(array $tokens, int $index, Providers $providers): ?EmbedNode
    {
        $token = $tokens[$index];
        $name = $token['name'];
        $attrs = $token['attrs'];

        if (!in_array($name, self::MARKER_TAGS, true) && $name !== 'video' && $name !== 'audio') {
            return null;
        }

        $end = Html::closingOffset($tokens, $index);

        if ($end === null) {
            // An element whose end tag never arrives. Wrapping it would mean guessing where it
            // finishes, and guessing wrong reparents the rest of the page.
            return null;
        }

        // A marker class beats the tag name: a TikTok placeholder is a `<blockquote>`, and an
        // Instagram one has sometimes been an `<iframe>` with the marker class on it.
        $marked = $providers->matchMarker(Html::classes($attrs), array_keys($attrs));

        if ($marked !== null) {
            return $this->makeNode($marked, Provider::KIND_SCRIPT, $token, $end, '');
        }

        if ($name === 'iframe') {
            $src = trim($attrs['src'] ?? $attrs['data-src'] ?? '');
            $provider = $src !== '' ? $providers->matchFrame($src) : null;

            return $this->makeNode($provider ?? $providers->generic(), Provider::KIND_IFRAME, $token, $end, $src);
        }

        if ($name === 'video' || $name === 'audio') {
            return $this->makeNode(
                $providers->generic(Provider::KIND_MEDIA),
                Provider::KIND_MEDIA,
                $token,
                $end,
                trim($attrs['src'] ?? ''),
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $token
     */
    private function makeNode(Provider $provider, string $kind, array $token, int $end, string $src): EmbedNode
    {
        $attrs = $token['attrs'];

        return new EmbedNode([
            'kind' => $kind,
            'provider' => $provider,
            'tagName' => $token['name'],
            'start' => $token['start'],
            'end' => $end,
            'tagStart' => $token['start'],
            'tagEnd' => $token['end'],
            'attrs' => $attrs,
            'src' => $src,
            'width' => $this->dimension($attrs['width'] ?? ''),
            'height' => $this->dimension($attrs['height'] ?? ''),
        ]);
    }

    /**
     * A dimension attribute as a number of pixels, or 0.
     *
     * `width="100%"` is a real and common thing to write, and it says nothing about a ratio — so
     * it has to come back as nothing rather than as 100.
     */
    private function dimension(string $value): int
    {
        $value = trim($value);

        if ($value === '' || !preg_match('/^\d+(\.\d+)?(px)?$/i', $value)) {
            return 0;
        }

        $number = (int)round((float)$value);

        return $number > 0 && $number <= 20000 ? $number : 0;
    }

    /** @param array<string, string> $attrs */
    private function isOptedOut(array $attrs): bool
    {
        foreach (self::SKIP_ATTRS as $attr) {
            if (array_key_exists($attr, $attrs)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ loader scripts

    /**
     * @param array<int, array<string, mixed>> $tokens
     * @return array{start: int, end: int, src: string, provider: string}|null
     */
    private function loaderScript(array $tokens, int $index, Providers $providers): ?array
    {
        $token = $tokens[$index];
        $src = trim($token['attrs']['src'] ?? '');

        if ($src === '') {
            return null;
        }

        $provider = $providers->matchScript($src);

        if ($provider === null) {
            return null;
        }

        $end = Html::closingOffset($tokens, $index) ?? $token['end'];

        return [
            'start' => $token['start'],
            'end' => $end,
            'src' => $src,
            'provider' => $provider->handle,
        ];
    }

    /**
     * The loader scripts that are actually safe to lift.
     *
     * Two conditions, and both are about not breaking a page to save a request:
     *
     *   1. The provider must have at least one placeholder here. A script with nothing to render
     *      may still be doing something — a follow button, an analytics shim, a widget Bed does
     *      not recognise — and removing it would just break that.
     *   2. It must sit outside every embed's range. A script nested inside a placeholder cannot be
     *      removed and re-inserted without colliding with the wrapper going round it.
     *
     * @param array<int, array{start: int, end: int, src: string, provider: string}> $scripts
     * @param EmbedNode[] $nodes
     * @return array<int, array{start: int, end: int, src: string, provider: string}>
     */
    private function liftableScripts(array $scripts, array $nodes): array
    {
        if ($scripts === [] || $nodes === []) {
            return [];
        }

        $present = [];

        foreach ($nodes as $node) {
            if ($node->isScriptEmbed()) {
                $present[$node->provider->handle] = true;
            }
        }

        $out = [];

        foreach ($scripts as $script) {
            if (!isset($present[$script['provider']])) {
                continue;
            }

            foreach ($nodes as $node) {
                if ($script['start'] < $node->end && $script['end'] > $node->start) {
                    continue 2;
                }
            }

            $out[] = $script;
        }

        return $out;
    }

    // ------------------------------------------------------------------ identity

    /**
     * What is embedded, as a stable key.
     *
     * The same tweet in two places has one embed key and two slot keys — that split is what lets
     * the ledger say "this embed measured 480px" while still knowing that only one of the two
     * places it appears is above the fold.
     */
    private function embedKey(EmbedNode $node): string
    {
        $identity = match (true) {
            $node->isScriptEmbed() => $this->markerIdentity($node),
            default => $this->normalizeUrl($node->src),
        };

        if ($identity === '') {
            // Nothing distinguishing on the element at all. Position is the only identity left,
            // which makes the key useless across a redesign and fine until then.
            $identity = '#' . $node->position;
        }

        return substr(sha1($node->provider->handle . '|' . $identity), 0, 40);
    }

    /**
     * A script-driven placeholder's identity, from whichever attribute the provider uses to say
     * what it is pointing at.
     */
    private function markerIdentity(EmbedNode $node): string
    {
        foreach (['cite', 'data-instgrm-permalink', 'data-video-id', 'data-id', 'data-href', 'href', 'data-src'] as $attr) {
            $value = trim($node->attrs[$attr] ?? '');

            if ($value !== '') {
                return $this->normalizeUrl($value);
            }
        }

        return '';
    }

    /**
     * A URL reduced to the part that identifies the thing.
     *
     * The scheme goes because the same embed is pasted both ways; the parameters that only affect
     * playback go because `?t=42` and `?autoplay=1` are the same video, and treating them as two
     * embeds would halve every sample count.
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return strtolower($url);
        }

        $host = strtolower($parts['host'] ?? '');
        $path = rtrim($parts['path'] ?? '', '/');
        $query = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach (['autoplay', 'mute', 'muted', 'start', 't', 'si', 'rel', 'controls', 'loop', 'modestbranding', 'enablejsapi', 'origin', 'widget_referrer'] as $volatile) {
                unset($query[$volatile]);
            }

            ksort($query);
        }

        return $host . $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }
}
