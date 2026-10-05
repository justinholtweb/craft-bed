<?php
/**
 * Whether one visitor can decide how much room an embed gets — checked in the plugin-testing
 * harness, partly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bed/tests/integration/security.php
 *
 * Until 5.0.1 a visitor could lift a signed token from any public page and send the whole sample
 * target themselves — twenty beacons, inside the rate limit — all reporting 20,000 px. The
 * reservation was the mean of those samples and collection then stopped, so the embed carried a
 * 20,000 px gap until an admin purged it. The rate limit itself believed any X-Forwarded-For.
 *
 * Self-cleaning: the slots it creates are deleted.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use GuzzleHttp\Client;
use justinholtweb\bed\models\Settings;
use justinholtweb\bed\Plugin;
use justinholtweb\bed\records\SlotRecord;
use justinholtweb\bed\services\Ledger;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$settings->enabled = true;
$settings->collectMetrics = true;
$settings->sampleTarget = 20;
$siteId = Craft::$app->getSites()->getPrimarySite()->id;
$run = bin2hex(random_bytes(4));
$uri = "/__bed-security/$run";

register_shutdown_function(function() use ($uri) {
    Craft::$app->getDb()->createCommand()->delete(SlotRecord::TABLE, ['uri' => $uri])->execute();
});

/** A token for one fresh embed slot, as a public page would carry it. */
$slot = static function(string $name) use ($plugin, $siteId, $uri, $run): array {
    $nodes = $plugin->scanner->scan("<iframe src=\"https://www.youtube.com/embed/$name$run\"></iframe>")->nodes;
    $key = substr(sha1("$name$run"), 0, 40);
    $nodes[0]->slotKey = $key;

    return [$plugin->metrics->token($siteId, $uri, $nodes), $key];
};

$reserved = static function(string $key) use ($plugin, $siteId): array {

    return $plugin->ledger->reservations($siteId, [$key])[$key]['heights'] ?? [];
};

echo "\nOne visitor\n";

check('sending the whole sample target counts once', function() use ($plugin, $slot) {
    [$token, $key] = $slot('flood');
    $recorded = 0;

    for ($i = 0; $i < 20; $i++) {
        $recorded += $plugin->metrics->collect($token, 1000, [['slot' => $key, 'height' => 9000]], 'attacker');
    }

    return $recorded === 1 ?: "recorded $recorded";
});

check('…so a poisoned sample among honest ones doesn’t move the reservation', function() use ($plugin, $slot, $reserved) {
    [$token, $key] = $slot('mixed');

    foreach (['a', 'b', 'c', 'd'] as $visitor) {
        $plugin->metrics->collect($token, 1000, [['slot' => $key, 'height' => 400]], "honest-$visitor");
    }
    $plugin->metrics->collect($token, 1000, [['slot' => $key, 'height' => 9000]], 'attacker');

    $heights = $reserved($key);

    return ($heights[1000] ?? $heights[array_key_first($heights)] ?? null) === 400 ?: json_encode($heights);
});

check('a report taller than ten times the viewport width is not a measurement', function() use ($plugin, $slot) {
    [$token, $key] = $slot('tall');

    return $plugin->metrics->collect($token, 800, [['slot' => $key, 'height' => 8001]], 'someone') === 0
        && $plugin->metrics->collect($token, 800, [['slot' => $key, 'height' => 8000]], 'someone-else') === 1
        ?: 'the cap is wrong';
});

echo "\nThe reservation\n";

check('the median, not the mean', function() {
    return Ledger::reserveHeight([400, 400, 410, 20000], false) === 405 ?: (string)Ledger::reserveHeight([400, 400, 410, 20000], false);
});

check('“tallest” ignores a sample far above the rest', function() {
    return Ledger::reserveHeight([400, 420, 560, 20000], true) === 560 ?: (string)Ledger::reserveHeight([400, 420, 560, 20000], true);
});

check('…but still finds room for an embed that is sometimes taller', function() {
    return Ledger::reserveHeight([400, 400, 590], true) === 590 ?: (string)Ledger::reserveHeight([400, 400, 590], true);
});

check('nothing measured reserves nothing', fn() => Ledger::reserveHeight([], false) === 0 && Ledger::reserveHeight([0, -5], true) === 0);

echo "\nThe beacon endpoint, over HTTP\n";

check('a forged X-Forwarded-For doesn’t buy a fresh rate-limit budget', function() use ($settings) {
    // Start early in a minute, so the window can't roll over mid-check.
    if ((int)date('s') > 40) {
        sleep(61 - (int)date('s'));
    }
    $minute = intdiv(time(), 60);
    Craft::$app->getCache()->delete(sprintf('bed:rate:collect:%s:%d', sha1('127.0.0.1'), $minute));
    Craft::$app->getCache()->delete(sprintf('bed:rate:collect:*:%d', $minute));

    $http = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false]);
    $limit = $settings->collectRateLimit;
    $statuses = [];

    for ($i = 0; $i <= $limit; $i++) {
        $statuses[] = $http->post('index.php?p=actions/bed/metrics/collect', [
            'headers' => ['Content-Type' => 'application/json', 'X-Forwarded-For' => "198.51.100.$i"],
            'body' => '{}',
        ])->getStatusCode();
    }

    return end($statuses) === 429 ?: json_encode(array_count_values($statuses));
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
