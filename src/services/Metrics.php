<?php

namespace justinholtweb\bed\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use justinholtweb\bed\models\EmbedNode;
use justinholtweb\bed\models\Settings;
use justinholtweb\bed\Plugin;
use yii\caching\TagDependency;

/**
 * The measurement loop.
 *
 * Reserving space for an embed whose height nobody knows is guesswork, and a guess that is too
 * big is as bad as a guess that is too small — one leaves a hole, the other still shifts the page.
 * So Bed does not guess. It renders the page, asks a sampled fraction of visitors what the embed
 * actually measured at their viewport width, and reserves that from then on.
 *
 * The endpoint that receives those answers is public, anonymous and CSRF-exempt, which is a thing
 * to design for rather than a thing to apologise for:
 *
 *   - The page hands out a token signed with Craft's own security key, listing exactly which
 *     slots are on it and what each one is. A client can only report on embeds that were on a
 *     page it was served, and it cannot invent a provider or a URI.
 *   - Every number is clamped and every viewport width is snapped to a fixed bucket on the
 *     server, so the worst a valid-but-dishonest report can do is drag a mean around inside a
 *     sane range.
 *   - Nothing per-visitor is written. A row is a count, a sum and a maximum.
 *   - A slot stops accepting samples once it has enough, and the ledger has a hard row cap.
 */
class Metrics extends Component
{
    /** How long a page's reservations stay cached. */
    public const CACHE_DURATION = 300;

    /** The most slots one token will carry. A page with more than this is measured in part. */
    public const MAX_SLOTS_PER_TOKEN = 24;

    /** Nothing on a web page is 20,000 pixels tall, and anything claiming to be is not measuring. */
    public const MAX_HEIGHT = 20000;

    /**
     * What Bed knows about the slots on a page.
     *
     * Cached, because this runs inside a response filter on every front-end request and the answer
     * changes only when a beacon arrives. The cache is tagged per page so recording a measurement
     * invalidates exactly the page it came from.
     *
     * @param EmbedNode[] $nodes
     * @return array<string, array{slotId: int, heights: array<int, int>, aboveFold: bool, full: int[], samples: int}>
     */
    public function reservations(int $siteId, string $uri, array $nodes): array
    {
        if ($nodes === []) {
            return [];
        }

        $keys = array_map(fn(EmbedNode $node) => $node->slotKey, $nodes);
        $cache = Craft::$app->getCache();
        $cacheKey = $this->cacheKey($siteId, $uri);
        $cached = $cache->get($cacheKey);

        // A cached answer is only usable if it covers every slot being asked about. When a page's
        // content changes its slot keys change with it, and the stale entry has to be seen as a
        // miss rather than quietly answering about embeds that are no longer there.
        if (is_array($cached) && $this->covers($cached, $keys)) {
            return array_intersect_key($cached, array_flip($keys));
        }

        $reservations = Plugin::getInstance()->ledger->reservations($siteId, $keys);

        foreach ($keys as $key) {
            $reservations[$key] ??= ['slotId' => 0, 'heights' => [], 'aboveFold' => false, 'full' => [], 'samples' => 0];
        }

        $cache->set(
            $cacheKey,
            $reservations,
            self::CACHE_DURATION,
            new TagDependency(['tags' => [$this->cacheTag($siteId, $uri)]]),
        );

        return $reservations;
    }

    /**
     * @param array<string, mixed> $cached
     * @param string[] $keys
     */
    private function covers(array $cached, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $cached)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ the token

    /**
     * A signed description of the slots on a page.
     *
     * `hashData()` is Craft's own keyed HMAC — the same primitive the CSRF token uses. Bed does
     * not hand-roll any crypto, and the key it signs with is the site's `securityKey`, so a token
     * from one install means nothing on another.
     *
     * @param EmbedNode[] $nodes
     */
    public function token(int $siteId, string $uri, array $nodes): string
    {
        $settings = Plugin::getInstance()->getSettings();

        $slots = [];

        foreach (array_slice($nodes, 0, self::MAX_SLOTS_PER_TOKEN) as $node) {
            $slots[$node->slotKey] = [
                'e' => $node->embedKey,
                'p' => $node->provider->handle,
                'k' => $node->kind,
                'i' => $node->position,
                's' => mb_substr($node->src, 0, 300),
            ];
        }

        $payload = [
            's' => $siteId,
            'u' => $uri,
            'x' => time() + ($settings->tokenTtlDays * 86400),
            'l' => $slots,
        ];

        return base64_encode(Craft::$app->getSecurity()->hashData(Json::encode($payload)));
    }

    /**
     * The payload behind a token, or null if it is not one Bed issued or it has expired.
     *
     * @return array{s: int, u: string, x: int, l: array<string, array<string, mixed>>}|null
     */
    public function verify(string $token): ?array
    {
        if ($token === '' || strlen($token) > 20000) {
            return null;
        }

        $raw = base64_decode($token, true);

        if ($raw === false) {
            return null;
        }

        $data = Craft::$app->getSecurity()->validateData($raw);

        if ($data === false) {
            return null;
        }

        try {
            $payload = Json::decode($data);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($payload) || !isset($payload['s'], $payload['u'], $payload['x'], $payload['l'])) {
            return null;
        }

        if (!is_array($payload['l']) || (int)$payload['x'] < time()) {
            return null;
        }

        return $payload;
    }

    // ------------------------------------------------------------------ collection

    /**
     * Folds a batch of reported measurements into the ledger.
     *
     * @param array<int, array<string, mixed>> $measurements Each `{slot, height, above}`.
     * @return int How many were actually recorded.
     */
    public function collect(string $token, int $viewportWidth, array $measurements): int
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled || !$settings->collectMetrics) {
            return 0;
        }

        $payload = $this->verify($token);

        if ($payload === null) {
            return 0;
        }

        if ($viewportWidth < 100 || $viewportWidth > 10000) {
            return 0;
        }

        $breakpoint = Settings::bucket($viewportWidth);
        $siteId = (int)$payload['s'];
        $uri = (string)$payload['u'];
        $ledger = Plugin::getInstance()->ledger;
        $recorded = 0;

        foreach (array_slice($measurements, 0, self::MAX_SLOTS_PER_TOKEN) as $measurement) {
            if (!is_array($measurement)) {
                continue;
            }

            $slotKey = (string)($measurement['slot'] ?? '');
            $slot = $payload['l'][$slotKey] ?? null;

            // The token is the allowlist. A slot key that was not on the page it was issued for
            // is not measurable, however well-formed the rest of the report is.
            if (!is_array($slot)) {
                continue;
            }

            $height = (int)round((float)($measurement['height'] ?? 0));

            if ($height < 1 || $height > self::MAX_HEIGHT) {
                continue;
            }

            $slotId = $ledger->slotId($siteId, $slotKey, [
                'embedKey' => (string)($slot['e'] ?? ''),
                'provider' => (string)($slot['p'] ?? 'generic'),
                'kind' => (string)($slot['k'] ?? 'iframe'),
                'uri' => $uri,
                'position' => (int)($slot['i'] ?? 0),
                'src' => (string)($slot['s'] ?? ''),
            ]);

            if ($slotId === null) {
                continue;
            }

            if ($this->isSatisfied($slotId, $breakpoint, $settings->sampleTarget)) {
                continue;
            }

            $ledger->record($slotId, $breakpoint, $height, !empty($measurement['above']));
            $recorded++;
        }

        if ($recorded > 0) {
            TagDependency::invalidate(Craft::$app->getCache(), $this->cacheTag($siteId, $uri));
        }

        return $recorded;
    }

    /**
     * Whether a slot already has enough samples at this width.
     *
     * Checked again here even though the page told the client the same thing, because the page
     * is a hint and this is the rule. Anything a client can be told, a client can ignore.
     */
    private function isSatisfied(int $slotId, int $breakpoint, int $target): bool
    {
        $samples = (new \craft\db\Query())
            ->select(['samples'])
            ->from(\justinholtweb\bed\records\MetricRecord::TABLE)
            ->where(['slotId' => $slotId, 'breakpoint' => $breakpoint])
            ->scalar();

        return $samples !== false && $samples !== null && (int)$samples >= $target;
    }

    // ------------------------------------------------------------------ cache keys

    private function cacheKey(int $siteId, string $uri): string
    {
        return 'bed:reservations:' . $siteId . ':' . sha1($uri);
    }

    private function cacheTag(int $siteId, string $uri): string
    {
        return 'bed:page:' . $siteId . ':' . sha1($uri);
    }
}
