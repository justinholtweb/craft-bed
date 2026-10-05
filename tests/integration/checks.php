<?php
/**
 * Bed integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bed/tests/integration/checks.php
 *
 * Covers what a unit fixture cannot: the tokeniser against real-world markup, the whole rewrite
 * end to end, signed tokens through Craft's own security component, and rows going into and out
 * of the database.
 *
 * Idempotent and self-cleaning. Every slot it writes is on a URI under `/__bed-checks/`, so it can
 * never collide with a measurement from a real page view, and all of it is deleted at the end —
 * including strays from a run that died mid-way. Every setting it changes is put back.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use justinholtweb\bed\helpers\Html;
use justinholtweb\bed\models\Provider;
use justinholtweb\bed\models\Settings;
use justinholtweb\bed\Plugin;
use justinholtweb\bed\records\MetricRecord;
use justinholtweb\bed\records\SlotRecord;
use justinholtweb\bed\services\Optimizer;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "Bed is not installed in this site.\n";
    exit(1);
}

/** @var Settings $settings */
$settings = $plugin->getSettings();
$original = $settings->toArray();

const TEST_URI = '/__bed-checks/page';

/** Everything this file wrote. */
$sweep = function() {
    $ids = (new Query())->select(['id'])->from(SlotRecord::TABLE)->where(['like', 'uri', '/__bed-checks/%', false])->column();

    if ($ids !== []) {
        Craft::$app->getDb()->createCommand()->delete(MetricRecord::TABLE, ['slotId' => $ids])->execute();
        Craft::$app->getDb()->createCommand()->delete(SlotRecord::TABLE, ['id' => $ids])->execute();
    }

    return count($ids);
};

$sweep();

$siteId = Craft::$app->getSites()->getPrimarySite()->id;

/** Rewrite a fragment with a known configuration. */
$optimize = function(string $html, string $uri = TEST_URI) use ($plugin, $siteId): string {
    return $plugin->optimizer->optimize($html, [
        'mode' => Optimizer::MODE_FRAGMENT,
        'siteId' => $siteId,
        'uri' => $uri,
    ]);
};

// =====================================================================================
section('The tokeniser');

check('reads a start tag and its attributes', function() {
    $tag = Html::scanStartTag('<iframe src="https://www.youtube.com/embed/abc" width="560" allowfullscreen>', 0);

    return $tag !== null
        && $tag['name'] === 'iframe'
        && $tag['attrs']['src'] === 'https://www.youtube.com/embed/abc'
        && $tag['attrs']['width'] === '560'
        && $tag['attrs']['allowfullscreen'] === ''
            ?: 'got ' . var_export($tag, true);
});

check('a `>` inside a quoted value does not end the tag', function() {
    $tag = Html::scanStartTag('<iframe srcdoc="<b>hi</b>" src="x"></iframe>', 0);

    return $tag !== null && $tag['attrs']['src'] === 'x' && $tag['attrs']['srcdoc'] === '<b>hi</b>'
        ?: 'got ' . var_export($tag['attrs'] ?? null, true);
});

check('single quotes and unquoted values both parse', function() {
    $tag = Html::scanStartTag("<iframe src='a b' width=560 loading=lazy>", 0);

    return $tag !== null && $tag['attrs']['src'] === 'a b' && $tag['attrs']['width'] === '560' && $tag['attrs']['loading'] === 'lazy'
        ?: 'got ' . var_export($tag['attrs'] ?? null, true);
});

check('a duplicate attribute keeps the first, like the HTML parser does', function() {
    $tag = Html::scanStartTag('<iframe src="first" src="second">', 0);

    return $tag['attrs']['src'] === 'first' ?: 'got ' . $tag['attrs']['src'];
});

check('a bare `<` in text is not a tag', function() {
    return Html::scanStartTag('a < b', 2) === null;
});

check('an iframe inside a script is text, not markup', function() {
    $tokens = Html::tokenize('<div><script>var s = "<iframe src=\'x\'>";</script></div>');
    $names = array_column($tokens, 'name');

    return !in_array('iframe', $names, true) ?: 'found ' . implode(',', $names);
});

check('an iframe inside a textarea is text, not markup', function() {
    $tokens = Html::tokenize('<textarea><iframe src="x"></iframe></textarea>');

    return !in_array('iframe', array_column($tokens, 'name'), true);
});

check('a commented-out iframe is skipped', function() {
    $tokens = Html::tokenize('<div><!-- <iframe src="x"></iframe> --></div>');

    return !in_array('iframe', array_column($tokens, 'name'), true);
});

check('`</scriptish>` does not close a `<script>`', function() {
    $tokens = Html::tokenize('<script>var a = "</scriptish>"; var b = 1;</script><iframe src="y"></iframe>');
    $names = array_column($tokens, 'name');

    return in_array('iframe', $names, true) ?: 'iframe outside the script was lost: ' . implode(',', $names);
});

check('nested elements of the same name close in the right order', function() {
    $html = '<blockquote class="a"><blockquote class="b">inner</blockquote>outer</blockquote>tail';
    $tokens = Html::tokenize($html);
    $end = Html::closingOffset($tokens, 0);

    return substr($html, 0, $end) === '<blockquote class="a"><blockquote class="b">inner</blockquote>outer</blockquote>'
        ?: 'got ' . var_export(substr($html, 0, $end), true);
});

check('an unclosed element reports no closing offset', function() {
    $tokens = Html::tokenize('<blockquote class="a">never closed');

    return Html::closingOffset($tokens, 0) === null;
});

check('`<iframe />` is not self-closing — its content runs to the end tag', function() {
    $html = '<iframe src="x" />fallback</iframe>after';
    $tokens = Html::tokenize($html);

    return $tokens[0]['selfClosing'] === false && Html::closingOffset($tokens, 0) === strlen('<iframe src="x" />fallback</iframe>')
        ?: 'selfClosing=' . var_export($tokens[0]['selfClosing'], true) . ' end=' . var_export(Html::closingOffset($tokens, 0), true);
});

check('splice applies edits back to front without shifting offsets', function() {
    $out = Html::splice('0123456789', [[2, 4, 'AA'], [6, 8, 'BBBB'], [0, 0, '>']]);

    return $out === '>01AA45BBBB89' ?: 'got ' . $out;
});

check('splice drops an overlapping edit rather than corrupting the string', function() {
    // Back to front is not negotiable, so the later edit is the one that survives and the
    // earlier one is dropped. Either way nothing is applied against an offset that has moved.
    $out = Html::splice('abcdefgh', [[1, 5, 'X'], [3, 7, 'Y']]);

    return $out === 'abcYh' ?: 'got ' . $out;
});

check('an attribute value is not double-encoded on the way out', function() {
    $tag = Html::renderStartTag('iframe', ['src' => 'https://x.test/?a=1&amp;b=2']);

    return $tag === '<iframe src="https://x.test/?a=1&amp;b=2">' ?: 'got ' . $tag;
});

// =====================================================================================
section('The provider registry');

check('a YouTube frame is recognised', function() use ($plugin) {
    $provider = $plugin->providers->matchFrame('https://www.youtube.com/embed/dQw4w9WgXcQ');

    return $provider?->handle === 'youtube' ?: 'got ' . ($provider?->handle ?? 'null');
});

check('a look-alike domain is not', function() use ($plugin) {
    $provider = $plugin->providers->matchFrame('https://notyoutube.com/embed/abc');

    return $provider === null ?: 'matched ' . $provider->handle;
});

check('a protocol-relative frame URL still matches', function() use ($plugin) {
    return $plugin->providers->matchFrame('//player.vimeo.com/video/123')?->handle === 'vimeo';
});

check('the privacy-preserving YouTube host matches too', function() use ($plugin) {
    return $plugin->providers->matchFrame('https://www.youtube-nocookie.com/embed/abc')?->handle === 'youtube';
});

check('a Google host is disambiguated by path', function() use ($plugin) {
    $maps = $plugin->providers->matchFrame('https://www.google.com/maps/embed?pb=x');
    $other = $plugin->providers->matchFrame('https://www.google.com/something/else');

    return $maps?->handle === 'googlemaps' && $other === null
        ?: 'maps=' . ($maps?->handle ?? 'null') . ' other=' . ($other?->handle ?? 'null');
});

check('a tweet placeholder is recognised by its class', function() use ($plugin) {
    return $plugin->providers->matchMarker(['twitter-tweet'], ['class'])?->handle === 'twitter';
});

check('a Pinterest placeholder is recognised by its attribute', function() use ($plugin) {
    return $plugin->providers->matchMarker([], ['data-pin-do', 'href'])?->handle === 'pinterest';
});

check('a loader script is traced back to its provider', function() use ($plugin) {
    return $plugin->providers->matchScript('https://platform.twitter.com/widgets.js')?->handle === 'twitter';
});

check('YouTube builds a poster URL without asking anybody', function() use ($plugin) {
    $poster = $plugin->providers->byHandle('youtube')->poster('https://www.youtube.com/embed/dQw4w9WgXcQ?rel=0');

    return $poster === 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg' ?: 'got ' . var_export($poster, true);
});

check('every provider handle is unique and every registry entry is well formed', function() use ($plugin) {
    foreach ($plugin->providers->all() as $handle => $provider) {
        if ($provider->handle !== $handle || $handle === '' || $provider->name === '') {
            return "bad entry: $handle";
        }

        if (!in_array($provider->kind, [Provider::KIND_IFRAME, Provider::KIND_SCRIPT, Provider::KIND_MEDIA], true)) {
            return "$handle has kind {$provider->kind}";
        }

        if ($provider->ratio !== null && (count($provider->ratio) !== 2 || $provider->ratio[1] <= 0)) {
            return "$handle has a broken ratio";
        }

        foreach ([...$provider->preconnect, ...$provider->dnsPrefetch] as $origin) {
            if (!str_starts_with($origin, 'https://') || str_contains(substr($origin, 8), '/')) {
                return "$handle has a non-origin hint: $origin";
            }
        }
    }

    return true;
});

check('every facade-capable provider can actually build a poster', function() use ($plugin) {
    foreach ($plugin->providers->facadeCapable() as $provider) {
        if ($provider->posterTemplate === null) {
            return $provider->handle . ' claims a facade but has no poster template';
        }
    }

    return true;
});

// =====================================================================================
section('The scan');

check('finds a frame, a tweet and a video in one document', function() use ($plugin) {
    $html = '<p>hi</p><iframe src="https://www.youtube.com/embed/a"></iframe>'
        . '<blockquote class="twitter-tweet" cite="https://x.com/a/status/1">t</blockquote>'
        . '<script async src="https://platform.twitter.com/widgets.js"></script>'
        . '<video src="/a.mp4"></video>';

    $scan = $plugin->scanner->scan($html);

    return count($scan->nodes) === 3
        && $scan->nodes[0]->provider->handle === 'youtube'
        && $scan->nodes[1]->provider->handle === 'twitter'
        && $scan->nodes[2]->isMedia()
        && count($scan->scripts) === 1
            ?: 'found ' . count($scan->nodes) . ' nodes and ' . count($scan->scripts) . ' scripts';
});

check('positions are assigned in document order', function() use ($plugin) {
    $scan = $plugin->scanner->scan('<iframe src="a"></iframe><iframe src="b"></iframe><iframe src="c"></iframe>');

    return array_map(fn($n) => $n->position, $scan->nodes) === [0, 1, 2];
});

check('an already-wrapped embed is left alone', function() use ($plugin) {
    $scan = $plugin->scanner->scan('<div class="bed" data-bed="x"><iframe src="https://www.youtube.com/embed/a"></iframe></div>');

    return $scan->isEmpty() ?: 'found ' . count($scan->nodes);
});

check('`data-bed-skip` opts an embed out', function() use ($plugin) {
    $scan = $plugin->scanner->scan('<div data-bed-skip><iframe src="https://www.youtube.com/embed/a"></iframe></div>');

    return $scan->isEmpty();
});

check('a loader script with no placeholder on the page is not lifted', function() use ($plugin) {
    $scan = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/a"></iframe><script async src="https://platform.twitter.com/widgets.js"></script>');

    return $scan->scripts === [] ?: 'would have lifted ' . count($scan->scripts);
});

check('a loader script nested inside its own placeholder is not lifted', function() use ($plugin) {
    $html = '<blockquote class="twitter-tweet"><script async src="https://platform.twitter.com/widgets.js"></script></blockquote>';
    $scan = $plugin->scanner->scan($html);

    return count($scan->nodes) === 1 && $scan->scripts === [] ?: 'lifted ' . count($scan->scripts);
});

check('the same embed on two pages shares an embed key', function() use ($plugin) {
    $a = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/x?autoplay=1"></iframe>')->nodes[0];
    $b = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/x?t=42"></iframe>')->nodes[0];

    return $a->embedKey === $b->embedKey ?: 'keys differ';
});

check('two different embeds do not', function() use ($plugin) {
    $a = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/x"></iframe>')->nodes[0];
    $b = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/y"></iframe>')->nodes[0];

    return $a->embedKey !== $b->embedKey;
});

check('`width="100%"` is not mistaken for a ratio', function() use ($plugin) {
    $node = $plugin->scanner->scan('<iframe src="https://example.test/x" width="100%" height="400"></iframe>')->nodes[0];

    return $node->width === 0 && $node->intrinsicRatio() === null ?: 'width=' . $node->width;
});

// =====================================================================================
section('The rewrite');

check('leaves a document with no embeds byte-identical', function() use ($optimize) {
    $html = "<p>Nothing to see.</p>\n<div class='x'>&amp; entities &lt;kept&gt;</div>";

    return $optimize($html) === $html ?: 'changed';
});

check('a frame gets a bed, a lazy attribute and a title', function() use ($optimize) {
    // Vimeo rather than YouTube, because YouTube is on the facade list by default and a facade
    // is a different shape of output entirely — it gets its own checks below.
    $out = $optimize('<iframe src="https://example.test/first"></iframe><iframe src="https://player.vimeo.com/video/12345" width="560" height="315"></iframe>');

    return str_contains($out, '<div class="bed bed--vimeo bed--iframe bed--ratio"')
        && str_contains($out, '--bed-ar:560/315')
        && str_contains($out, 'loading="lazy"')
        && str_contains($out, 'title="Vimeo embed"')
        && str_contains($out, '</iframe></div>')
            ?: 'got ' . $out;
});

check('the first embed on the page is left eager', function() use ($optimize) {
    $out = $optimize('<iframe src="https://vimeo.test/x"></iframe>', '/__bed-checks/eager');

    return !str_contains($out, 'loading="lazy"') ?: 'got ' . $out;
});

check('an author’s own title and loading attribute are respected', function() use ($optimize) {
    $out = $optimize('<iframe src="https://example.test/first"></iframe><iframe src="https://example.test/a" title="Mine" loading="eager"></iframe>');

    return str_contains($out, 'title="Mine"') && str_contains($out, 'loading="eager"') && !str_contains($out, 'loading="lazy"')
        ?: 'got ' . $out;
});

check('a video below the fold gets `preload="none"`', function() use ($optimize) {
    // A real embed first: `eagerCount` counts embeds, not elements, so the video has to be the
    // second one on the page for the lazy path to be the one under test.
    $out = $optimize('<iframe src="https://example.test/first"></iframe><video src="/a.mp4" width="640" height="360"></video>');

    return str_contains($out, 'preload="none"') ?: 'got ' . $out;
});

check('a tweet gets a bed and its loader script is lifted onto it', function() use ($optimize) {
    $out = $optimize(
        '<blockquote class="twitter-tweet" cite="https://x.com/a/status/1">t</blockquote>'
        . '<script async src="https://platform.twitter.com/widgets.js"></script>'
    );

    return str_contains($out, 'data-bed-script="https://platform.twitter.com/widgets.js"')
        && !str_contains($out, '<script async src="https://platform.twitter.com/widgets.js">')
            ?: 'got ' . $out;
});

check('a script embed reserves the provider’s fallback height', function() use ($optimize) {
    $out = $optimize('<blockquote class="tiktok-embed" cite="https://tiktok.test/1">t</blockquote><script src="https://www.tiktok.com/embed.js"></script>');
    $css = implode('', Craft::$app->getView()->css);

    return str_contains($out, 'bed--tiktok bed--script')
        && str_contains($css, '--bed-min:750px')
            ?: "html: $out\ncss: $css";
});

check('everything outside an embed survives the rewrite unchanged', function() use ($optimize) {
    $before = '<article><h1>Title &amp; more</h1><p>Before</p>';
    $after = '<p>After</p><pre>&lt;iframe src="x"&gt;</pre></article>';
    $out = $optimize($before . '<iframe src="https://example.test/a"></iframe>' . $after);

    return str_starts_with($out, $before) && str_ends_with($out, $after) ?: 'got ' . $out;
});

check('a second pass over already-optimised markup changes nothing', function() use ($optimize) {
    $once = $optimize('<p>x</p><iframe src="https://www.youtube.com/embed/twice"></iframe>');
    $twice = $optimize($once);

    return $once === $twice ?: "once:\n$once\ntwice:\n$twice";
});

check('an embed inside a `<script>` is not rewritten', function() use ($optimize) {
    $html = '<script>document.write(\'<iframe src="https://www.youtube.com/embed/x"></iframe>\');</script>';

    return $optimize($html) === $html ?: 'rewrote a string literal';
});

// =====================================================================================
section('Facades');

check('a YouTube frame becomes a poster and a play button', function() use ($optimize, $plugin, $settings) {
    $settings->facadeProviders = ['youtube'];
    $out = $optimize('<p>x</p><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315"></iframe>');

    return str_contains($out, 'bed--facade')
        && str_contains($out, 'i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg')
        && str_contains($out, '<template data-bed-frame><iframe')
        && str_contains($out, 'data-bed-activate="autoplay=1"')
            ?: 'got ' . $out;
});

check('the real frame is preserved inside the template, without its lazy attribute', function() use ($optimize, $settings) {
    $settings->facadeProviders = ['youtube'];
    $out = $optimize('<p>x</p><iframe src="https://www.youtube.com/embed/abc123" loading="lazy" allowfullscreen></iframe>');

    preg_match('#<template data-bed-frame>(.*?)</template>#s', $out, $m);

    return isset($m[1])
        && str_contains($m[1], 'src="https://www.youtube.com/embed/abc123"')
        && str_contains($m[1], 'allowfullscreen')
        && !str_contains($m[1], 'loading=')
            ?: 'got ' . ($m[1] ?? $out);
});

check('a provider not on the facade list keeps its frame', function() use ($optimize, $settings) {
    $settings->facadeProviders = [];
    $out = $optimize('<p>x</p><iframe src="https://www.youtube.com/embed/plain"></iframe>');

    return !str_contains($out, 'bed--facade') && str_contains($out, '<iframe');
});

check('no facade is built when the runtime is switched off', function() use ($optimize, $settings) {
    $settings->facadeProviders = ['youtube'];
    $settings->registerJs = false;
    $out = $optimize('<p>x</p><iframe src="https://www.youtube.com/embed/norun"></iframe>');
    $settings->registerJs = true;

    return !str_contains($out, 'bed--facade') ?: 'built a facade nothing can open';
});

check('no loader script is lifted when the runtime is switched off', function() use ($optimize, $settings) {
    $settings->registerJs = false;
    $out = $optimize('<blockquote class="twitter-tweet">t</blockquote><script async src="https://platform.twitter.com/widgets.js"></script>');
    $settings->registerJs = true;

    return str_contains($out, 'platform.twitter.com/widgets.js"></script>') ?: 'removed a script nothing would put back';
});

$settings->facadeProviders = $original['facadeProviders'];

// =====================================================================================
section('Media queries and reserved space');

check('the smallest bucket has no lower bound', function() {
    return Optimizer::mediaQuery(360) === '@media (max-width:360px)' ?: Optimizer::mediaQuery(360);
});

check('a middle bucket is bounded on both sides', function() {
    return Optimizer::mediaQuery(768) === '@media (min-width:481px) and (max-width:768px)' ?: Optimizer::mediaQuery(768);
});

check('the widest bucket has no upper bound', function() {
    return Optimizer::mediaQuery(1920) === '@media (min-width:1537px)' ?: Optimizer::mediaQuery(1920);
});

check('the buckets tile the whole range with no gaps and no overlaps', function() {
    $covered = [];

    foreach ([100, 360, 361, 480, 481, 768, 769, 1024, 1025, 1280, 1281, 1536, 1537, 4000] as $width) {
        $covered[] = Settings::bucket($width);
    }

    return $covered === [360, 360, 480, 480, 768, 768, 1024, 1024, 1280, 1280, 1536, 1536, 1920, 1920]
        ?: implode(',', $covered);
});

// =====================================================================================
section('The ledger');

$slotId = null;

check('a slot can be created from token metadata', function() use ($plugin, $siteId, &$slotId) {
    $slotId = $plugin->ledger->slotId($siteId, str_repeat('a', 40), [
        'embedKey' => str_repeat('b', 40),
        'provider' => 'twitter',
        'kind' => 'script',
        'uri' => TEST_URI,
        'position' => 0,
        'src' => 'https://x.com/a/status/1',
    ]);

    return is_int($slotId) && $slotId > 0 ?: 'got ' . var_export($slotId, true);
});

check('asking for the same slot again returns the same row', function() use ($plugin, $siteId, $slotId) {
    $again = $plugin->ledger->slotId($siteId, str_repeat('a', 40), [
        'embedKey' => str_repeat('b', 40),
        'provider' => 'twitter',
        'kind' => 'script',
        'uri' => TEST_URI,
        'position' => 0,
        'src' => '',
    ]);

    return $again === $slotId ?: "got $again, expected $slotId";
});

check('measurements accumulate as a count, a sum and a maximum', function() use ($plugin, $slotId) {
    $plugin->ledger->record($slotId, 768, 400, true);
    $plugin->ledger->record($slotId, 768, 600, false);
    $plugin->ledger->record($slotId, 768, 500, true);

    $row = (new Query())->from(MetricRecord::TABLE)->where(['slotId' => $slotId, 'breakpoint' => 768])->one();

    return (int)$row['samples'] === 3
        && (int)$row['heightSum'] === 1500
        && (int)$row['heightMax'] === 600
        && (int)$row['aboveFold'] === 2
            ?: 'got ' . json_encode($row);
});

check('the reservation is the mean when that is the strategy', function() use ($plugin, $siteId, $settings) {
    $settings->reserveStrategy = Settings::RESERVE_MEAN;
    $reservations = $plugin->ledger->reservations($siteId, [str_repeat('a', 40)]);

    return ($reservations[str_repeat('a', 40)]['heights'][768] ?? null) === 500
        ?: 'got ' . json_encode($reservations);
});

check('and the tallest when that is', function() use ($plugin, $siteId, $settings) {
    $settings->reserveStrategy = Settings::RESERVE_MAX;
    $reservations = $plugin->ledger->reservations($siteId, [str_repeat('a', 40)]);
    $settings->reserveStrategy = Settings::RESERVE_MEAN;

    return ($reservations[str_repeat('a', 40)]['heights'][768] ?? null) === 600
        ?: 'got ' . json_encode($reservations);
});

check('a majority above the fold makes the slot eager', function() use ($plugin, $siteId) {
    $reservations = $plugin->ledger->reservations($siteId, [str_repeat('a', 40)]);

    return ($reservations[str_repeat('a', 40)]['aboveFold'] ?? null) === true;
});

check('the slot’s denormalised sample count kept up', function() use ($slotId) {
    return (int)(new Query())->select(['samples'])->from(SlotRecord::TABLE)->where(['id' => $slotId])->scalar() === 3;
});

check('a measured slot reserves space in the rendered CSS', function() use ($plugin, $siteId, $optimize) {
    // Build a page whose slot key is the one just measured, by measuring the slot the scanner
    // actually produces for this markup rather than the other way round.
    $html = '<p>a</p><blockquote class="twitter-tweet" cite="https://x.com/a/status/9">t</blockquote>'
        . '<script async src="https://platform.twitter.com/widgets.js"></script>';

    $node = $plugin->scanner->scan($html)->nodes[0];
    $slotKey = substr(sha1(TEST_URI . '|' . $node->position . '|' . $node->embedKey), 0, 40);

    $id = $plugin->ledger->slotId($siteId, $slotKey, [
        'embedKey' => $node->embedKey,
        'provider' => 'twitter',
        'kind' => 'script',
        'uri' => TEST_URI,
        'position' => $node->position,
        'src' => '',
    ]);

    $plugin->ledger->record($id, 1024, 812, false);
    Craft::$app->getCache()->flush();

    $out = $optimize($html);
    $css = implode('', Craft::$app->getView()->css);

    return str_contains($out, 'data-bed="' . $slotKey . '"')
        && str_contains($css, '[data-bed="' . $slotKey . '"]{--bed-min:812px}')
            ?: "css:\n$css";
});

// =====================================================================================
section('Tokens and collection');

check('a token round-trips through Craft’s security component', function() use ($plugin, $siteId) {
    $nodes = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/tok"></iframe>')->nodes;
    $nodes[0]->slotKey = str_repeat('c', 40);

    $token = $plugin->metrics->token($siteId, TEST_URI, $nodes);
    $payload = $plugin->metrics->verify($token);

    return $payload !== null
        && (int)$payload['s'] === $siteId
        && $payload['u'] === TEST_URI
        && isset($payload['l'][str_repeat('c', 40)])
            ?: 'got ' . var_export($payload, true);
});

check('a tampered token is rejected', function() use ($plugin, $siteId) {
    $nodes = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/tamper"></iframe>')->nodes;
    $nodes[0]->slotKey = str_repeat('d', 40);

    $token = $plugin->metrics->token($siteId, TEST_URI, $nodes);
    $raw = base64_decode($token, true);
    $raw = substr($raw, 0, -5) . 'XXXXX';

    return $plugin->metrics->verify(base64_encode($raw)) === null;
});

check('an expired token is rejected', function() use ($plugin, $siteId, $settings) {
    $settings->tokenTtlDays = 1;
    $nodes = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/old"></iframe>')->nodes;
    $nodes[0]->slotKey = str_repeat('e', 40);

    // Sign a payload that expired yesterday, using the same primitive the plugin does.
    $payload = ['s' => $siteId, 'u' => TEST_URI, 'x' => time() - 86400, 'l' => [str_repeat('e', 40) => []]];
    $token = base64_encode(Craft::$app->getSecurity()->hashData(craft\helpers\Json::encode($payload)));

    $settings->tokenTtlDays = 7;

    return $plugin->metrics->verify($token) === null;
});

check('garbage is rejected without throwing', function() use ($plugin) {
    return $plugin->metrics->verify('') === null
        && $plugin->metrics->verify('not base64 !!!') === null
        && $plugin->metrics->verify(base64_encode('nonsense')) === null;
});

check('a measurement for a slot not named in the token is refused', function() use ($plugin, $siteId) {
    $nodes = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/scope"></iframe>')->nodes;
    $nodes[0]->slotKey = str_repeat('f', 40);
    $token = $plugin->metrics->token($siteId, '/__bed-checks/scope', $nodes);

    $recorded = $plugin->metrics->collect($token, 1000, [
        ['slot' => str_repeat('9', 40), 'height' => 500, 'above' => 1],
    ]);

    return $recorded === 0 ?: "recorded $recorded";
});

check('a measurement named in the token is accepted', function() use ($plugin, $siteId) {
    $nodes = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/scope"></iframe>')->nodes;
    $nodes[0]->slotKey = str_repeat('f', 40);
    $token = $plugin->metrics->token($siteId, '/__bed-checks/scope', $nodes);

    return $plugin->metrics->collect($token, 1000, [
        ['slot' => str_repeat('f', 40), 'height' => 480, 'above' => 1],
    ]) === 1;
});

check('the accepted measurement landed in the right bucket', function() use ($plugin, $siteId) {
    $id = (new Query())->select(['id'])->from(SlotRecord::TABLE)->where(['siteId' => $siteId, 'slotKey' => str_repeat('f', 40)])->scalar();
    $row = (new Query())->from(MetricRecord::TABLE)->where(['slotId' => $id])->one();

    return (int)$row['breakpoint'] === 1024 && (int)$row['heightSum'] === 480 ?: 'got ' . json_encode($row);
});

check('an absurd height is refused', function() use ($plugin, $siteId) {
    $nodes = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/scope"></iframe>')->nodes;
    $nodes[0]->slotKey = str_repeat('f', 40);
    $token = $plugin->metrics->token($siteId, '/__bed-checks/scope', $nodes);

    return $plugin->metrics->collect($token, 1000, [
        ['slot' => str_repeat('f', 40), 'height' => 999999, 'above' => 1],
        ['slot' => str_repeat('f', 40), 'height' => 0, 'above' => 0],
        ['slot' => str_repeat('f', 40), 'height' => -5, 'above' => 0],
    ]) === 0;
});

check('an absurd viewport width is refused outright', function() use ($plugin, $siteId) {
    $nodes = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/scope"></iframe>')->nodes;
    $nodes[0]->slotKey = str_repeat('f', 40);
    $token = $plugin->metrics->token($siteId, '/__bed-checks/scope', $nodes);

    return $plugin->metrics->collect($token, 99999, [['slot' => str_repeat('f', 40), 'height' => 400]]) === 0
        && $plugin->metrics->collect($token, 0, [['slot' => str_repeat('f', 40), 'height' => 400]]) === 0;
});

check('a slot stops accepting samples once it has enough', function() use ($plugin, $siteId, $settings, $original) {
    $settings->sampleTarget = 2;

    $nodes = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/full"></iframe>')->nodes;
    $nodes[0]->slotKey = str_repeat('7', 40);
    $token = $plugin->metrics->token($siteId, '/__bed-checks/full', $nodes);

    // Three different visitors: since 5.0.1 one visitor only ever counts once per slot.
    $run = bin2hex(random_bytes(4));
    $first = $plugin->metrics->collect($token, 800, [['slot' => str_repeat('7', 40), 'height' => 100]], "a$run");
    $second = $plugin->metrics->collect($token, 800, [['slot' => str_repeat('7', 40), 'height' => 100]], "b$run");
    $third = $plugin->metrics->collect($token, 800, [['slot' => str_repeat('7', 40), 'height' => 100]], "c$run");

    $settings->sampleTarget = $original['sampleTarget'];

    return [$first, $second, $third] === [1, 1, 0] ?: "got $first, $second, $third";
});

check('collection is off when the plugin is off', function() use ($plugin, $siteId, $settings) {
    $nodes = $plugin->scanner->scan('<iframe src="https://www.youtube.com/embed/off"></iframe>')->nodes;
    $nodes[0]->slotKey = str_repeat('8', 40);
    $token = $plugin->metrics->token($siteId, '/__bed-checks/off', $nodes);

    $settings->enabled = false;
    $recorded = $plugin->metrics->collect($token, 800, [['slot' => str_repeat('8', 40), 'height' => 300]]);
    $settings->enabled = true;

    return $recorded === 0;
});

// =====================================================================================
section('Scope and safety');

check('an excluded URI is matched by pattern', function() use ($settings, $original) {
    $settings->excludeUris = ['checkout/*', '/private'];

    $result = [
        $settings->excludes('/checkout/cart'),
        $settings->excludes('checkout/cart'),
        $settings->excludes('/private'),
        $settings->excludes('/public'),
    ];

    $settings->excludeUris = $original['excludeUris'];

    return $result === [true, true, true, false] ?: json_encode($result);
});

check('an editable-table value normalises to a flat list', function() {
    $s = new Settings();
    $s->excludeUris = [['value' => 'a/*'], ['value' => ''], ['value' => 'b']];
    $s->validate();

    return $s->excludeUris === ['a/*', 'b'] ?: json_encode($s->excludeUris);
});

check('clearing an editable table clears the setting', function() {
    $s = new Settings();
    $s->facadeProviders = [];
    $s->validate();

    return $s->facadeProviders === [];
});

check('a nonsense reserve strategy fails validation', function() {
    $s = new Settings();
    $s->reserveStrategy = 'whatever';

    return !$s->validate(['reserveStrategy']);
});

check('no setting is required, so a fresh install can save', function() {
    $s = new Settings();

    return $s->validate() ?: 'errors: ' . json_encode($s->getErrors());
});

check('the whole plugin is a no-op when disabled', function() use ($optimize, $settings) {
    $settings->enabled = false;
    $html = '<p>x</p><iframe src="https://www.youtube.com/embed/none"></iframe>';
    $out = $optimize($html);
    $settings->enabled = true;

    return $out === $html ?: 'rewrote while disabled';
});

check('an HTML content type is recognised and a feed is not', function() use ($plugin) {
    return $plugin->isHtmlResponse('text/html; charset=UTF-8')
        && !$plugin->isHtmlResponse('application/rss+xml')
        && !$plugin->isHtmlResponse('application/json');
});

check('pruning removes a slot nobody has seen in a long time', function() use ($plugin, $siteId) {
    $key = str_repeat('1', 40);
    $id = $plugin->ledger->slotId($siteId, $key, [
        'embedKey' => str_repeat('2', 40),
        'provider' => 'generic',
        'kind' => 'iframe',
        'uri' => '/__bed-checks/old',
        'position' => 0,
        'src' => '',
    ]);

    Craft::$app->getDb()->createCommand()->update(
        SlotRecord::TABLE,
        ['lastSeen' => craft\helpers\Db::prepareDateForDb(new DateTime('-400 days', new DateTimeZone('UTC')))],
        ['id' => $id],
    )->execute();

    $plugin->ledger->prune(90);

    return (new Query())->from(SlotRecord::TABLE)->where(['id' => $id])->exists() === false;
});

check('deleting a slot takes its measurements with it', function() use ($plugin, $siteId) {
    $key = str_repeat('3', 40);
    $id = $plugin->ledger->slotId($siteId, $key, [
        'embedKey' => str_repeat('4', 40),
        'provider' => 'generic',
        'kind' => 'iframe',
        'uri' => '/__bed-checks/cascade',
        'position' => 0,
        'src' => '',
    ]);

    $plugin->ledger->record($id, 768, 200, false);
    Craft::$app->getDb()->createCommand()->delete(SlotRecord::TABLE, ['id' => $id])->execute();

    return (new Query())->from(MetricRecord::TABLE)->where(['slotId' => $id])->exists() === false;
});

check('stats survive being asked about an empty ledger', function() use ($plugin) {
    $stats = $plugin->ledger->stats();

    return isset($stats['slots'], $stats['measured'], $stats['samples'], $stats['providers'], $stats['full']);
});

// =====================================================================================
section('Cleaning up');

check('every row this run created is gone', function() use ($sweep) {
    $sweep();

    return (new Query())->from(SlotRecord::TABLE)->where(['like', 'uri', '/__bed-checks/%', false])->exists() === false;
});

check('every setting this run changed is back', function() use ($settings, $original) {
    foreach ($original as $attribute => $value) {
        $settings->$attribute = $value;
    }

    return $settings->toArray() == $original;
});

echo "\n" . str_repeat('─', 60) . "\n";
echo "  $passed passed, $failed failed\n\n";

exit($failed === 0 ? 0 : 1);
