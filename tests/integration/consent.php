<?php
/**
 * Bed consent checks — embeds held behind a notice until the visitor's consent allows them.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bed/tests/integration/consent.php
 *
 * What matters most here is what is *not* in the output: no frame, poster or loader script
 * outside the inert template before consent, no resource hint to a held provider, and nothing
 * about the visitor — the markup has to be byte-identical whoever asks, because a full-page cache
 * will hand it to everybody.
 *
 * Toss is switched in memory only (its kit setting and its resolved visitor state), so nothing
 * reaches project config and nothing needs a cookie. Every setting is put back at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\bed\models\Settings;
use justinholtweb\bed\Plugin;
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
$siteId = Craft::$app->getSites()->getPrimarySite()->id;

// Measurement is irrelevant here and would write slots; the runtime has to be on for most checks.
$settings->collectMetrics = false;
$settings->registerJs = true;
$settings->deferToToss = true;

/** Rewrites with a fresh view, so registered CSS and HTML from one check never leak into the next. */
$optimize = function(string $html, string $mode = Optimizer::MODE_FRAGMENT) use ($plugin, $siteId): string {
    Craft::$app->set('view', new craft\web\View());
    $plugin->set('optimizer', new Optimizer());

    return $plugin->optimizer->optimize($html, [
        'mode' => $mode,
        'siteId' => $siteId,
        'uri' => '/__bed-checks/consent',
    ]);
};

$page = fn(string $body) => "<!doctype html><html><head><title>t</title></head><body>$body</body></html>";

/** The page with every `<template>` removed — what a browser renders before anything is released. */
$live = fn(string $html) => preg_replace('#<template\b.*?</template>#s', '', $html);

$island = function(string $html): ?array {
    if (!preg_match('#<script type="application/json" id="bed-consent">(.*?)</script>#s', $html, $m)) {
        return null;
    }

    return json_decode($m[1], true);
};

$tossInstalled = Craft::$app->getPlugins()->isPluginEnabled('toss');
$tossOriginalKits = $tossInstalled ? (array)justinholtweb\toss\Plugin::getInstance()->getSettings()->kits : null;

/** Switches Toss's consent kit in memory and sets this request's Toss visitor state directly. */
function tossSet(bool $kit, ?array $granted = null, bool $decided = false): void
{
    $toss = justinholtweb\toss\Plugin::getInstance();
    $tossSettings = $toss->getSettings();
    $handle = justinholtweb\toss\kits\CookieConsentKit::handle();
    $kits = (array)$tossSettings->kits;
    $kits[$handle] = array_merge((array)($kits[$handle] ?? []), ['enabled' => $kit]);
    $tossSettings->kits = $kits;

    $state = null;

    if ($granted !== null) {
        $state = new justinholtweb\toss\models\ConsentState();
        $state->granted = array_values(array_unique(array_merge(['necessary'], $granted)));
        $state->decided = $decided;
        $state->source = 'cookie';
    }

    (new ReflectionProperty($toss->consent, '_state'))->setValue($toss->consent, $state);
}

if ($tossInstalled) {
    tossSet(false);
}

// =====================================================================================
section('Off by default');

check('with nothing listed, no embed is held', function() use ($optimize, $settings) {
    $settings->consentProviders = [];
    $out = $optimize('<p>x</p><iframe src="https://player.vimeo.com/video/1" width="560" height="315"></iframe>');

    return !str_contains($out, 'bed--gated') && !str_contains($out, 'data-bed-gate') && str_contains($out, '<iframe')
        ?: 'got ' . $out;
});

check('a fresh settings model holds nothing and validates', function() {
    $fresh = new Settings();

    return $fresh->consentProviders === [] && $fresh->consentSource === Settings::CONSENT_CLICK && $fresh->validate()
        ?: json_encode($fresh->getErrors());
});

// =====================================================================================
section('Holding a frame');

$settings->consentProviders = ['vimeo' => 'marketing', 'youtube' => 'marketing'];

check('a listed frame is parked in an inert template behind a notice', function() use ($optimize, $live) {
    $out = $optimize('<p>x</p><iframe src="https://player.vimeo.com/video/12345" width="560" height="315"></iframe>');
    $visible = $live($out);

    return str_contains($out, 'bed--gated')
        && str_contains($out, 'data-bed-gate="marketing"')
        && str_contains($out, 'data-bed-consent="pending"')
        && str_contains($out, 'data-bed-provider="vimeo"')
        && str_contains($out, '--bed-ar:560/315')
        && str_contains($out, 'This embed loads content from Vimeo, which may set marketing cookies.')
        && preg_match('#<template data-bed-held><iframe[^>]*src="https://player.vimeo.com/video/12345"#', $out)
        && !str_contains($visible, '<iframe')
            ?: 'got ' . $out;
});

check('the held frame keeps Bed’s own rewrites — title, referrer policy', function() use ($optimize) {
    $out = $optimize('<p>x</p><iframe src="https://player.vimeo.com/video/7"></iframe>');
    preg_match('#<template data-bed-held>(.*?)</template>#s', $out, $m);

    return isset($m[1]) && str_contains($m[1], 'title="Vimeo embed"') && str_contains($m[1], 'referrerpolicy=') && str_contains($m[1], '</iframe>')
        ?: 'got ' . ($m[1] ?? $out);
});

check('the notice offers a load button, a hidden settings button and a link to the source', function() use ($optimize) {
    $out = $optimize('<p>x</p><iframe src="https://player.vimeo.com/video/8"></iframe>');

    return str_contains($out, '<button type="button" class="bed-consent__load" data-bed-load>Load the embed</button>')
        && str_contains($out, 'data-bed-manage hidden>Cookie settings</button>')
        && str_contains($out, '<a class="bed-consent__link" href="https://player.vimeo.com/video/8" target="_blank" rel="noopener noreferrer">Open on player.vimeo.com</a>')
            ?: 'got ' . $out;
});

check('a facade provider is held poster and all — the poster is a third-party request too', function() use ($optimize, $live, $settings) {
    $settings->facadeProviders = ['youtube'];
    $out = $optimize('<p>x</p><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315"></iframe>');
    $visible = $live($out);

    return str_contains($out, 'bed--facade bed--gated')
        && str_contains($out, '<template data-bed-held><button type="button" class="bed-facade"')
        && str_contains($out, 'i.ytimg.com/vi/dQw4w9WgXcQ')
        && !str_contains($visible, 'ytimg') && !str_contains($visible, '<iframe') && !str_contains($visible, 'bed-facade"')
            ?: 'got ' . $out;
});

check('a held provider gets no resource hint', function() use ($optimize, $page) {
    $out = $optimize($page('<iframe src="https://player.vimeo.com/video/9"></iframe><iframe src="https://w.soundcloud.com/player/?url=x"></iframe>'), Optimizer::MODE_DOCUMENT);

    return !str_contains($out, 'vimeo.com" rel') && !preg_match('#<link[^>]+vimeo#', $out) && preg_match('#<link[^>]+soundcloud#', $out)
        ? true
        : 'hints: ' . implode(' ', (preg_match_all('#<link[^>]+>#', $out, $m) ? $m[0] : []));
});

check('one consent island per page, naming the source and nothing about the visitor', function() use ($optimize, $page, $island) {
    $out = $optimize($page('<iframe src="https://player.vimeo.com/video/1"></iframe><iframe src="https://player.vimeo.com/video/2"></iframe>'), Optimizer::MODE_DOCUMENT);
    $config = $island($out);

    return substr_count($out, 'id="bed-consent"') === 1 && $config === ['source' => 'click']
        ?: 'got ' . json_encode($config) . ' x' . substr_count($out, 'id="bed-consent"');
});

check('the island goes through the view once in fragment mode, however many fields call |bed', function() use ($plugin, $siteId) {
    Craft::$app->set('view', new craft\web\View());
    $plugin->set('optimizer', new Optimizer());

    foreach ([1, 2, 3] as $n) {
        $plugin->optimizer->optimize('<iframe src="https://player.vimeo.com/video/' . $n . '"></iframe>', ['mode' => Optimizer::MODE_FRAGMENT, 'siteId' => $siteId, 'uri' => '/__bed-checks/consent']);
    }

    $html = Craft::$app->getView()->getBodyHtml();

    return substr_count($html, 'id="bed-consent"') === 1 ?: 'got ' . $html;
});

check('a second pass over held markup changes nothing', function() use ($optimize, $page) {
    $once = $optimize($page('<iframe src="https://player.vimeo.com/video/3"></iframe>'), Optimizer::MODE_DOCUMENT);
    $twice = $optimize($once, Optimizer::MODE_DOCUMENT);

    return $once === $twice ?: "once:\n$once\ntwice:\n$twice";
});

check('the notice text is escaped, including a custom one', function() use ($optimize, $settings) {
    $settings->consentMessage = '<img src=x onerror=alert(1)> {provider} & {host} wants {category}';
    $out = $optimize('<p>x</p><iframe src="https://player.vimeo.com/video/4"></iframe>');
    $settings->consentMessage = '';

    return str_contains($out, '&lt;img src=x onerror=alert(1)&gt; Vimeo &amp; player.vimeo.com wants marketing') && !str_contains($out, '<img src=x')
        ?: 'got ' . $out;
});

check('a javascript: source is never offered as a link', function() use ($optimize, $settings) {
    $settings->consentProviders = ['generic' => 'marketing'];
    $out = $optimize('<p>x</p><iframe src="javascript:alert(1)"></iframe>');
    $settings->consentProviders = ['vimeo' => 'marketing', 'youtube' => 'marketing'];

    return str_contains($out, 'bed--gated') && !str_contains($out, 'bed-consent__link') ?: 'got ' . $out;
});

check('the load button can be switched off', function() use ($optimize, $settings) {
    $settings->consentClickToLoad = false;
    $out = $optimize('<p>x</p><iframe src="https://player.vimeo.com/video/5"></iframe>');
    $settings->consentClickToLoad = true;

    return str_contains($out, 'bed--gated') && !str_contains($out, 'data-bed-load') ?: 'got ' . $out;
});

check('without the runtime, a held frame stays held — consent fails closed', function() use ($optimize, $live, $settings) {
    $settings->registerJs = false;
    $out = $optimize('<p>x</p><iframe src="https://player.vimeo.com/video/6"></iframe>');
    $settings->registerJs = true;

    return str_contains($out, 'bed--gated')
        && !str_contains($live($out), '<iframe')
        && !str_contains($out, 'data-bed-load')
        && !str_contains($out, 'data-bed-manage')
        && str_contains($out, 'bed-consent__link')
            ?: 'got ' . $out;
});

check('a held `<video>` is parked with its sources', function() use ($optimize, $live, $settings) {
    $settings->consentProviders = ['media' => 'preferences'];
    $out = $optimize('<p>x</p><video controls width="640" height="360"><source src="https://cdn.example.test/a.mp4"></video>');
    $settings->consentProviders = ['vimeo' => 'marketing', 'youtube' => 'marketing'];

    return str_contains($out, 'data-bed-gate="preferences"')
        && preg_match('#<template data-bed-held><video[^>]*>.*<source src="https://cdn.example.test/a.mp4"></video></template>#s', $out)
        && !str_contains($live($out), '<source')
            ?: 'got ' . $out;
});

// =====================================================================================
section('Holding a script embed');

check('the loader script is lifted even with deferral off, and the post text stays readable', function() use ($optimize, $settings) {
    $settings->consentProviders = ['twitter' => 'marketing'];
    $settings->deferScripts = false;
    $out = $optimize('<blockquote class="twitter-tweet" cite="https://x.com/a/status/1"><p>Hello</p></blockquote><script async src="https://platform.twitter.com/widgets.js"></script>');
    $settings->deferScripts = true;

    return !str_contains($out, '<script async src="https://platform.twitter.com/widgets.js">')
        && str_contains($out, 'data-bed-script="https://platform.twitter.com/widgets.js"')
        && str_contains($out, 'data-bed-gate="marketing"')
        && str_contains($out, 'Load X / Twitter posts')
        && preg_match('#<div class="bed-consent" data-bed-notice>.*</div><blockquote class="twitter-tweet"#s', $out)
        && str_contains($out, '<p>Hello</p>')
            ?: 'got ' . $out;
});

check('a script embed is held whatever its loader, without one being listed', function() use ($optimize) {
    $out = $optimize('<blockquote class="twitter-tweet"><p>Hello</p></blockquote>');

    return str_contains($out, 'data-bed-gate="marketing"') && !str_contains($out, 'data-bed-script') ?: 'got ' . $out;
});

check('without the runtime, a held provider’s loader script is still removed', function() use ($optimize, $settings) {
    $settings->registerJs = false;
    $out = $optimize('<blockquote class="twitter-tweet"><p>Hi</p></blockquote><script async src="https://platform.twitter.com/widgets.js"></script>');
    $settings->registerJs = true;

    return !str_contains($out, 'widgets.js') && str_contains($out, '<p>Hi</p>') ?: 'got ' . $out;
});

check('an unlisted provider’s loader is untouched by consent', function() use ($optimize, $settings) {
    $settings->deferScripts = false;
    $out = $optimize('<blockquote class="instagram-media">i</blockquote><script async src="https://www.instagram.com/embed.js"></script>');
    $settings->deferScripts = true;
    $settings->consentProviders = ['vimeo' => 'marketing', 'youtube' => 'marketing'];

    return str_contains($out, 'instagram.com/embed.js"></script>') && !str_contains($out, 'bed--gated') ?: 'got ' . $out;
});

// =====================================================================================
section('Which providers');

check('`*` covers every recognised provider but never an unrecognised frame or a video', function() use ($optimize, $settings) {
    $settings->consentProviders = ['*' => 'marketing'];
    $out = $optimize('<p>x</p><iframe src="https://w.soundcloud.com/player/?url=x"></iframe><iframe src="https://example.test/own"></iframe><video src="/v.mp4"></video>');

    return substr_count($out, 'data-bed-gate=') === 1 && str_contains($out, 'data-bed-provider="soundcloud"')
        ?: 'got ' . $out;
});

check('a provider named outright overrides `*`', function() use ($optimize, $settings) {
    $settings->consentProviders = ['*' => 'marketing', 'googlemaps' => 'preferences'];
    $out = $optimize('<p>x</p><iframe src="https://www.google.com/maps/embed?pb=1"></iframe><iframe src="https://w.soundcloud.com/player/?url=x"></iframe>');

    return str_contains($out, 'data-bed-gate="preferences" data-bed-consent="pending" data-bed-provider="googlemaps"')
        && str_contains($out, 'data-bed-gate="marketing" data-bed-consent="pending" data-bed-provider="soundcloud"')
            ?: 'got ' . $out;
});

check('an embed inside Eye’s own consent figure is not asked about twice', function() use ($optimize, $settings) {
    $settings->consentProviders = ['*' => 'marketing', 'generic' => 'marketing'];
    $out = $optimize('<p>x</p><figure class="eye" data-eye="{}"><div class="eye-stage"><template data-eye-template><iframe src="https://www.youtube.com/embed/eye1"></iframe></template></div></figure><iframe src="https://www.youtube.com/embed/bare"></iframe>');
    $settings->consentProviders = ['vimeo' => 'marketing', 'youtube' => 'marketing'];

    return substr_count($out, 'data-bed-gate=') === 1 && str_contains($out, 'embed/eye1"') ?: 'got ' . $out;
});

// =====================================================================================
section('Settings');

check('editable-table rows normalise to provider → category', function() {
    $s = new Settings();
    $s->consentProviders = [
        ['provider' => 'YouTube', 'category' => 'marketing'],
        ['provider' => 'googlemaps', 'category' => 'preferences'],
        ['provider' => '', 'category' => 'marketing'],
    ];
    $s->validate(['consentProviders']);

    return $s->consentProviders === ['youtube' => 'marketing', 'googlemaps' => 'preferences'] ?: json_encode($s->consentProviders);
});

check('a plain list of handles means marketing; necessary holds nothing and is dropped', function() {
    $s = new Settings();
    $s->consentProviders = ['youtube', 'vimeo'];
    $s->validate(['consentProviders']);
    $a = $s->consentProviders;
    $s->consentProviders = ['youtube' => 'necessary'];
    $s->validate(['consentProviders']);

    return $a === ['youtube' => 'marketing', 'vimeo' => 'marketing'] && $s->consentProviders === [] ?: json_encode([$a, $s->consentProviders]);
});

check('a category that is not a plain name is an error, not a silently ungated embed', function() {
    $s = new Settings();
    $s->consentProviders = ['youtube' => 'mark eting"'];

    return !$s->validate(['consentProviders']) && $s->hasErrors('consentProviders') ?: 'accepted it';
});

check('the cookie source needs a cookie name, and nothing else does', function() {
    $s = new Settings();
    $s->consentSource = Settings::CONSENT_COOKIE;
    $missing = !$s->validate(['consentCookieName']);
    $s->consentCookieName = 'my_consent';
    $ok = $s->validate(['consentCookieName', 'consentSource']);
    $s->consentSource = 'bogus';

    return $missing && $ok && !$s->validate(['consentSource']) ?: 'validation wrong';
});

check('the cookie source puts its name and match in the island', function() use ($optimize, $island, $settings, $page) {
    $settings->consentSource = Settings::CONSENT_COOKIE;
    $settings->consentCookieName = 'my_consent';
    $settings->consentCookieMatch = '{category}=yes';
    $settings->deferToToss = false;
    $config = $island($optimize($page('<iframe src="https://player.vimeo.com/video/1"></iframe>'), Optimizer::MODE_DOCUMENT));
    $settings->consentSource = Settings::CONSENT_CLICK;
    $settings->consentCookieName = '';
    $settings->consentCookieMatch = '{category}';
    $settings->deferToToss = true;

    return $config === ['source' => 'cookie', 'cookie' => ['name' => 'my_consent', 'match' => '{category}=yes']] ?: json_encode($config);
});

// =====================================================================================
section('Toss — the family consent manager');

check('Bed names no Toss class before checking Toss is enabled', function() {
    $source = file_get_contents('/var/www/craft-bed/src/services/Consent.php');
    preg_match('/function tossIsActive\(\): bool\s*\{(.*?)\n    \}/s', $source, $m);
    $body = $m[1] ?? '';
    $guard = strpos($body, "isPluginEnabled('toss')");
    $first = strpos($body, 'justinholtweb\\toss');

    if ($guard === false || $first === false || $guard > $first) {
        return 'tossIsActive() must check isPluginEnabled before naming a Toss class';
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/var/www/craft-bed/src'));

    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && $file->getFilename() !== 'Consent.php' && str_contains(file_get_contents($file->getPathname()), 'justinholtweb\\toss')) {
            return $file->getFilename() . ' references Toss directly';
        }
    }

    return true;
});

if (!$tossInstalled) {
    echo "  - Toss is not installed in this harness; the remaining Toss checks are skipped\n";
} else {
    check('with Toss’s consent kit off, Bed uses its own source', function() use ($plugin, $optimize, $island, $page) {
        tossSet(false);
        $config = $island($optimize($page('<iframe src="https://player.vimeo.com/video/1"></iframe>'), Optimizer::MODE_DOCUMENT));

        return !$plugin->consent->tossIsActive() && $config === ['source' => 'click'] ?: json_encode($config);
    });

    check('turning Toss’s kit on is enough — Bed defers with no setting changed', function() use ($plugin, $optimize, $island, $page) {
        tossSet(true);
        $config = $island($optimize($page('<iframe src="https://player.vimeo.com/video/1"></iframe>'), Optimizer::MODE_DOCUMENT));

        return $plugin->consent->defersToToss() && $config === ['source' => 'toss'] ?: json_encode($config);
    });

    check('`deferToToss` off keeps Bed on its own source while Toss is on', function() use ($plugin, $settings, $optimize, $island, $page) {
        tossSet(true);
        $settings->deferToToss = false;
        $settings->consentSource = Settings::CONSENT_COOKIEBOT;
        $config = $island($optimize($page('<iframe src="https://player.vimeo.com/video/1"></iframe>'), Optimizer::MODE_DOCUMENT));
        $settings->deferToToss = true;
        $settings->consentSource = Settings::CONSENT_CLICK;

        return $config === ['source' => 'cookiebot'] ?: json_encode($config);
    });

    check('the page is byte-identical for a visitor who granted, refused or has not decided', function() use ($optimize, $page) {
        $html = $page('<iframe src="https://player.vimeo.com/video/42"></iframe><blockquote class="twitter-tweet">t</blockquote><script async src="https://platform.twitter.com/widgets.js"></script>');
        $outputs = [];

        foreach ([[['marketing'], true], [[], true], [[], false], [null, false]] as [$granted, $decided]) {
            tossSet(true, $granted, $decided);
            $outputs[] = $optimize($html, Optimizer::MODE_DOCUMENT);
        }

        tossSet(true);

        return count(array_unique($outputs)) === 1 && str_contains($outputs[0], 'data-bed-consent="pending"')
            ?: 'the markup varied with the visitor’s answer';
    });

    check('a visitor who granted still gets the held markup — the browser releases it', function() use ($optimize, $live) {
        tossSet(true, ['marketing'], true);
        $out = $optimize('<p>x</p><iframe src="https://player.vimeo.com/video/43"></iframe>');
        tossSet(true);

        return !str_contains($live($out), '<iframe') ?: 'released on the server';
    });

    tossSet(false);
    $toss = justinholtweb\toss\Plugin::getInstance();
    $toss->getSettings()->kits = $tossOriginalKits;
    (new ReflectionProperty($toss->consent, '_state'))->setValue($toss->consent, null);
}

// =====================================================================================
section('The runtime');

check('the runtime ships the consent reader, Toss’s recipe and the public API', function() {
    $js = file_get_contents('/var/www/craft-bed/src/web/assets/runtime/dist/bed.js');

    return str_contains($js, "window.Toss.onConsent(callback)")
        && str_contains($js, "document.addEventListener('toss:consent'")
        && str_contains($js, 'template[data-bed-held]')
        && str_contains($js, 'consent: function (category, granted)')
        && !preg_match('/readCookie\([\'"]toss/i', $js)
            ?: 'runtime is missing part of the consent contract';
});

// =====================================================================================
section('Cleaning up');

check('every setting this run changed is back', function() use ($settings, $original) {
    foreach ($original as $attribute => $value) {
        $settings->$attribute = $value;
    }

    return $settings->toArray() == $original;
});

echo "\n" . str_repeat('─', 60) . "\n";
echo "  $passed passed, $failed failed\n\n";

exit($failed === 0 ? 0 : 1);
