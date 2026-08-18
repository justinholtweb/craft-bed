<?php

namespace justinholtweb\bed\records;

use craft\db\ActiveRecord;

/**
 * A place an embed appears: one site, one URI, one position, one embed.
 *
 * Rows are created by the collector, never by the renderer. Rendering a page must not write to
 * the database — a response filter that inserts a row per page view has turned every cache miss
 * into a write, and a plugin that makes a site slower to make its embeds faster has not helped.
 *
 * @property int $id
 * @property int $siteId
 * @property string $slotKey
 * @property string $embedKey
 * @property string $provider
 * @property string $kind
 * @property string $uri
 * @property int $position
 * @property string|null $sampleSrc
 * @property int $samples
 * @property string $lastSeen
 */
class SlotRecord extends ActiveRecord
{
    public const TABLE = '{{%bed_slots}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
