<?php

namespace justinholtweb\bed\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use justinholtweb\bed\models\Settings;
use justinholtweb\bed\Plugin;
use justinholtweb\bed\records\MetricRecord;
use justinholtweb\bed\records\SlotRecord;
use Throwable;
use yii\db\Exception as DbException;

/**
 * The slot ledger — the only thing in Bed that reads or writes `bed_slots` and `bed_metrics`.
 *
 * Everything here is on the collection path or the control panel, never on the render path. The
 * renderer's one question ("what do you know about these slots?") is answered by
 * {@see reservations()}, which is a single read the caller is expected to cache.
 */
class Ledger extends Component
{
    /**
     * Finds or creates the row for a slot.
     *
     * The metadata comes from a signed token, never from the request body, so the provider and
     * kind stored here are the ones Bed itself decided when it rendered the page.
     *
     * @param array{embedKey: string, provider: string, kind: string, uri: string, position: int, src: string} $meta
     * @return int|null The slot id, or null if the ledger is full.
     */
    public function slotId(int $siteId, string $slotKey, array $meta): ?int
    {
        $id = (new Query())
            ->select(['id'])
            ->from(SlotRecord::TABLE)
            ->where(['siteId' => $siteId, 'slotKey' => $slotKey])
            ->scalar();

        if ($id !== false && $id !== null) {
            return (int)$id;
        }

        if ($this->isFull()) {
            return null;
        }

        $now = Db::prepareDateForDb(new \DateTime('now', new \DateTimeZone('UTC')));

        $row = [
            'siteId' => $siteId,
            'slotKey' => $slotKey,
            'embedKey' => $meta['embedKey'],
            'provider' => $meta['provider'],
            'kind' => $meta['kind'],
            'uri' => mb_substr($meta['uri'], 0, 500),
            'position' => $meta['position'],
            'sampleSrc' => $meta['src'] !== '' ? mb_substr($meta['src'], 0, 2000) : null,
            'samples' => 0,
            'lastSeen' => $now,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ];

        try {
            Craft::$app->getDb()->createCommand()->insert(SlotRecord::TABLE, $row)->execute();

            return (int)Craft::$app->getDb()->getLastInsertID(Craft::$app->getDb()->getSchema()->getRawTableName(SlotRecord::TABLE));
        } catch (DbException $e) {
            // Two beacons for a brand new slot can arrive at the same moment. The unique index is
            // what makes that safe; losing the race just means reading the row the winner wrote.
            $id = (new Query())
                ->select(['id'])
                ->from(SlotRecord::TABLE)
                ->where(['siteId' => $siteId, 'slotKey' => $slotKey])
                ->scalar();

            if ($id !== false && $id !== null) {
                return (int)$id;
            }

            Craft::error('Could not record a slot: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }
    }

    /**
     * Folds one measurement into the aggregate for a slot at a breakpoint.
     *
     * Arithmetic in the `UPDATE` rather than read-modify-write, so two beacons landing together
     * both count. The insert is the fallback and the update is the common case, because a slot
     * that is being measured has usually been measured before.
     */
    public function record(int $slotId, int $breakpoint, int $height, bool $aboveFold): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime('now', new \DateTimeZone('UTC')));

        $updated = $db->createCommand()->update(
            MetricRecord::TABLE,
            [
                'samples' => new \yii\db\Expression('[[samples]] + 1'),
                'heightSum' => new \yii\db\Expression('[[heightSum]] + :h', [':h' => $height]),
                'heightMax' => new \yii\db\Expression('GREATEST([[heightMax]], :h2)', [':h2' => $height]),
                'aboveFold' => new \yii\db\Expression('[[aboveFold]] + :a', [':a' => $aboveFold ? 1 : 0]),
                'dateUpdated' => $now,
            ],
            ['slotId' => $slotId, 'breakpoint' => $breakpoint],
        )->execute();

        if ($updated === 0) {
            try {
                $db->createCommand()->insert(MetricRecord::TABLE, [
                    'slotId' => $slotId,
                    'breakpoint' => $breakpoint,
                    'samples' => 1,
                    'heightSum' => $height,
                    'heightMax' => $height,
                    'aboveFold' => $aboveFold ? 1 : 0,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ])->execute();
            } catch (DbException) {
                // Lost the race to create the row; the winner's row is the one to add to.
                $db->createCommand()->update(
                    MetricRecord::TABLE,
                    [
                        'samples' => new \yii\db\Expression('[[samples]] + 1'),
                        'heightSum' => new \yii\db\Expression('[[heightSum]] + :h', [':h' => $height]),
                        'heightMax' => new \yii\db\Expression('GREATEST([[heightMax]], :h2)', [':h2' => $height]),
                        'aboveFold' => new \yii\db\Expression('[[aboveFold]] + :a', [':a' => $aboveFold ? 1 : 0]),
                        'dateUpdated' => $now,
                    ],
                    ['slotId' => $slotId, 'breakpoint' => $breakpoint],
                )->execute();
            }
        }

        $db->createCommand()->update(
            SlotRecord::TABLE,
            ['samples' => new \yii\db\Expression('[[samples]] + 1'), 'lastSeen' => $now, 'dateUpdated' => $now],
            ['id' => $slotId],
        )->execute();

        $this->appendHeight($slotId, $breakpoint, $height);
    }

    /**
     * Adds a height to the row's list, up to the sample target.
     *
     * Read-modify-write, so under a mutex: two beacons for one slot at once must not drop one.
     * A busy lock skips the height rather than queueing — the counters above are already right,
     * and a list one short is still a fair sample.
     */
    private function appendHeight(int $slotId, int $breakpoint, int $height): void
    {
        $mutex = Craft::$app->getMutex();
        $lock = "bed:heights:$slotId:$breakpoint";

        if (!$mutex->acquire($lock, 2)) {
            return;
        }

        try {
            $where = ['slotId' => $slotId, 'breakpoint' => $breakpoint];
            $stored = (new Query())->select(['heights'])->from(MetricRecord::TABLE)->where($where)->scalar();
            $heights = is_string($stored) ? (json_decode($stored, true) ?: []) : [];
            $limit = max(1, Plugin::getInstance()->getSettings()->sampleTarget);

            if (count($heights) < $limit) {
                $heights[] = $height;
                Craft::$app->getDb()->createCommand()
                    ->update(MetricRecord::TABLE, ['heights' => json_encode(array_values($heights))], $where)
                    ->execute();
            }
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * The height to reserve, from the measured heights.
     *
     * The median, not the mean: it takes more than half the samples — from as many visitors, since
     * each counts once — to move it, where a mean moves as far as one bad sample likes. The "max"
     * strategy takes the tallest sample within half again of the median, so an embed that is
     * sometimes taller still gets room, but one absurd report does not decide the page.
     *
     * @param int[] $heights
     */
    public static function reserveHeight(array $heights, bool $useMax): int
    {
        $heights = array_values(array_filter(array_map('intval', $heights), static fn(int $h) => $h > 0));

        if ($heights === []) {
            return 0;
        }

        sort($heights);
        $n = count($heights);
        $median = $n % 2 === 1
            ? $heights[intdiv($n, 2)]
            : (int)round(($heights[$n / 2 - 1] + $heights[$n / 2]) / 2);

        if (!$useMax) {
            return $median;
        }

        $ceiling = $median * 1.5;
        $max = $median;

        foreach ($heights as $h) {
            if ($h <= $ceiling && $h > $max) {
                $max = $h;
            }
        }

        return $max;
    }

    /**
     * Everything known about a set of slots, in one query.
     *
     * @param string[] $slotKeys
     * @return array<string, array{slotId: int, heights: array<int, int>, aboveFold: bool, full: int[], samples: int}>
     */
    public function reservations(int $siteId, array $slotKeys): array
    {
        if ($slotKeys === []) {
            return [];
        }

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $useMax = $settings->reserveStrategy === Settings::RESERVE_MAX;

        $rows = (new Query())
            ->select([
                'slots.id AS slotId',
                'slots.slotKey',
                'metrics.breakpoint',
                'metrics.samples',
                'metrics.heightSum',
                'metrics.heightMax',
                'metrics.heights',
                'metrics.aboveFold',
            ])
            ->from(['slots' => SlotRecord::TABLE])
            ->leftJoin(['metrics' => MetricRecord::TABLE], '[[metrics.slotId]] = [[slots.id]]')
            ->where(['slots.siteId' => $siteId, 'slots.slotKey' => $slotKeys])
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $key = (string)$row['slotKey'];

            $out[$key] ??= [
                'slotId' => (int)$row['slotId'],
                'heights' => [],
                'aboveFold' => false,
                'full' => [],
                'samples' => 0,
            ];

            if ($row['breakpoint'] === null) {
                continue;
            }

            $samples = (int)$row['samples'];

            if ($samples < 1) {
                continue;
            }

            $breakpoint = (int)$row['breakpoint'];
            // Rows measured since 5.0.1 carry their heights and are reserved by median. Older rows
            // only have a sum and a max, and keep the old arithmetic until they are re-measured.
            $list = is_string($row['heights'] ?? null) ? (json_decode($row['heights'], true) ?: []) : [];
            $height = $list !== []
                ? self::reserveHeight($list, $useMax)
                : ($useMax ? (int)$row['heightMax'] : (int)round(((int)$row['heightSum']) / $samples));

            if ($height > 0) {
                $out[$key]['heights'][$breakpoint] = $height;
            }

            $out[$key]['samples'] += $samples;

            // Above the fold at *any* measured width counts as above the fold everywhere, because
            // the two mistakes are not symmetrical: eagerly loading an embed nobody scrolls to
            // costs some bandwidth, and lazily loading the one at the top of the page costs the
            // Largest Contentful Paint. Only one of those is worth being wrong about.
            if (((int)$row['aboveFold']) / $samples >= 0.5) {
                $out[$key]['aboveFold'] = true;
            }

            if ($samples >= $settings->sampleTarget) {
                $out[$key]['full'][] = $breakpoint;
            }
        }

        return $out;
    }

    /** Whether the ledger has hit its row cap. */
    public function isFull(): bool
    {
        $max = Plugin::getInstance()->getSettings()->maxSlots;

        return $this->count() >= $max;
    }

    public function count(?int $siteId = null): int
    {
        $query = (new Query())->from(SlotRecord::TABLE);

        if ($siteId !== null) {
            $query->where(['siteId' => $siteId]);
        }

        return (int)$query->count();
    }

    /** A query over the ledger for the control panel and the console. */
    public function query(): Query
    {
        return (new Query())
            ->select([
                'slots.id',
                'slots.siteId',
                'slots.slotKey',
                'slots.embedKey',
                'slots.provider',
                'slots.kind',
                'slots.uri',
                'slots.position',
                'slots.sampleSrc',
                'slots.samples',
                'slots.lastSeen',
                'slots.dateCreated',
            ])
            ->from(['slots' => SlotRecord::TABLE]);
    }

    /** @return array<int, array<string, mixed>> Every breakpoint row for one slot. */
    public function measurements(int $slotId): array
    {
        return (new Query())
            ->from(MetricRecord::TABLE)
            ->where(['slotId' => $slotId])
            ->orderBy(['breakpoint' => SORT_ASC])
            ->all();
    }

    /** Drops slots nobody has measured in a while. */
    public function prune(?int $days = null): int
    {
        $days = $days ?? Plugin::getInstance()->getSettings()->retentionDays;

        if ($days < 1) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb(new \DateTime("-{$days} days", new \DateTimeZone('UTC')));

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(SlotRecord::TABLE, ['<', 'lastSeen', $cutoff])
            ->execute();
    }

    /** Empties the ledger, for one site or all of them. */
    public function purge(?int $siteId = null): int
    {
        $condition = $siteId !== null ? ['siteId' => $siteId] : '';

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(SlotRecord::TABLE, $condition)
            ->execute();
    }

    /**
     * A summary for the settings screen and `craft.bed.stats`.
     *
     * @return array{slots: int, measured: int, samples: int, providers: array<string, int>, full: bool}
     */
    public function stats(): array
    {
        try {
            $slots = $this->count();
            $measured = (int)(new Query())->from(SlotRecord::TABLE)->where(['>', 'samples', 0])->count();
            $samples = (int)(new Query())->from(SlotRecord::TABLE)->sum('samples');

            $providers = [];

            foreach ((new Query())->select(['provider', 'c' => 'COUNT(*)'])->from(SlotRecord::TABLE)->groupBy(['provider'])->all() as $row) {
                $providers[(string)$row['provider']] = (int)$row['c'];
            }

            arsort($providers);

            return [
                'slots' => $slots,
                'measured' => $measured,
                'samples' => $samples,
                'providers' => $providers,
                'full' => $slots >= Plugin::getInstance()->getSettings()->maxSlots,
            ];
        } catch (Throwable) {
            // The settings screen is reachable before the install migration has run.
            return ['slots' => 0, 'measured' => 0, 'samples' => 0, 'providers' => [], 'full' => false];
        }
    }
}
