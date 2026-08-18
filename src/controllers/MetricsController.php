<?php

namespace justinholtweb\bed\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use justinholtweb\bed\Plugin;
use Throwable;
use yii\web\Response;

/**
 * The collection endpoint.
 *
 * Public, anonymous and CSRF-exempt, because it is called by `sendBeacon` from a page that may
 * have been served out of a full-page cache hours ago — there is no session to carry a token and
 * no response to read one from. What makes that safe is not a header:
 *
 *   - the signed token names the exact slots that may be reported on, and Bed issued it;
 *   - every number is clamped and the viewport width is snapped to a bucket on the server;
 *   - nothing per-visitor is written, only counts and sums;
 *   - one address gets a fixed number of beacons a minute, and the ledger has a row cap.
 *
 * It answers 204 to everything it accepts and never says what it did with the data, because a
 * measurement endpoint that reports back is an oracle for whatever it can be asked about.
 */
class MetricsController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    public function actionCollect(): Response
    {
        $this->requirePostRequest();

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = '';

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enabled || !$settings->collectMetrics) {
            $response->setStatusCode(204);

            return $response;
        }

        if (!$this->withinRateLimit($settings->collectRateLimit)) {
            $response->setStatusCode(429);

            return $response;
        }

        try {
            // The raw body rather than Craft's body params. A beacon posts a JSON document, and
            // asking a request that parsed it as a form for `measurements[0][slot]` is a way to
            // get an empty array on some inputs and a surprise on others.
            $body = Json::decode(Craft::$app->getRequest()->getRawBody());
        } catch (Throwable) {
            $response->setStatusCode(204);

            return $response;
        }

        if (!is_array($body)) {
            $response->setStatusCode(204);

            return $response;
        }

        $measurements = $body['measurements'] ?? [];

        if (is_array($measurements) && $measurements !== []) {
            try {
                $plugin->metrics->collect(
                    (string)($body['token'] ?? ''),
                    (int)($body['width'] ?? 0),
                    $measurements,
                );
            } catch (Throwable $e) {
                Craft::error('Could not record measurements: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        $response->setStatusCode(204);

        return $response;
    }

    /**
     * A per-address budget, held in the cache.
     *
     * Deliberately approximate — two beacons landing in the same millisecond can both read the
     * same count. The point is a ceiling on a flood, not an exact quota, and paying for a lock on
     * a write this cheap would cost more than the write.
     */
    private function withinRateLimit(int $perMinute): bool
    {
        $ip = Craft::$app->getRequest()->getUserIP();

        if ($ip === null) {
            return true;
        }

        $cache = Craft::$app->getCache();
        $key = 'bed:collect:' . sha1($ip);
        $count = (int)$cache->get($key);

        if ($count >= $perMinute) {
            return false;
        }

        $cache->set($key, $count + 1, 60);

        return true;
    }
}
