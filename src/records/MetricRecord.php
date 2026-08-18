<?php

namespace justinholtweb\bed\records;

use craft\db\ActiveRecord;

/**
 * What one slot measured at one viewport width.
 *
 * An aggregate and only an aggregate — a count, a sum, a maximum and a tally of how often the
 * embed's top was inside the initial viewport. There is no row per visitor here, and nothing
 * about who sent it, because none of that is needed to reserve the right amount of space.
 *
 * @property int $id
 * @property int $slotId
 * @property int $breakpoint
 * @property int $samples
 * @property int $heightSum
 * @property int $heightMax
 * @property int $aboveFold
 */
class MetricRecord extends ActiveRecord
{
    public const TABLE = '{{%bed_metrics}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }

    /** The mean measured height, rounded. */
    public function mean(): int
    {
        return $this->samples > 0 ? (int)round($this->heightSum / $this->samples) : 0;
    }

    /** Whether this breakpoint usually put the embed inside the first screenful. */
    public function isAboveFold(): bool
    {
        return $this->samples > 0 && ($this->aboveFold / $this->samples) >= 0.5;
    }
}
