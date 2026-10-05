<?php

namespace justinholtweb\bed\migrations;

use craft\db\Migration;
use craft\db\Table;
use justinholtweb\bed\records\MetricRecord;
use justinholtweb\bed\records\SlotRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(SlotRecord::TABLE)) {
            $this->createTable(SlotRecord::TABLE, [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),

                // Where the embed is: the page and the position on it, hashed together with what
                // the embed is. Forty hex characters, so `char` and not `string` — a variable
                // width column on the hottest lookup in the plugin buys nothing.
                'slotKey' => $this->char(40)->notNull(),

                // What the embed is, on its own. The same tweet on two pages shares this.
                'embedKey' => $this->char(40)->notNull(),

                'provider' => $this->string(64)->notNull(),
                'kind' => $this->string(16)->notNull(),
                'uri' => $this->string(500)->notNull()->defaultValue(''),
                'position' => $this->smallInteger()->notNull()->defaultValue(0),
                'sampleSrc' => $this->text(),

                // Denormalised across every breakpoint, so the index screen can sort by how much
                // Bed actually knows without a join per row.
                'samples' => $this->integer()->notNull()->defaultValue(0),

                'lastSeen' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, SlotRecord::TABLE, ['siteId', 'slotKey'], true);
            $this->createIndex(null, SlotRecord::TABLE, ['embedKey'], false);
            $this->createIndex(null, SlotRecord::TABLE, ['provider'], false);
            $this->createIndex(null, SlotRecord::TABLE, ['lastSeen'], false);
            $this->createIndex(null, SlotRecord::TABLE, ['siteId', 'uri'], false);

            $this->addForeignKey(null, SlotRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
        }

        if (!$this->db->tableExists(MetricRecord::TABLE)) {
            $this->createTable(MetricRecord::TABLE, [
                'id' => $this->primaryKey(),
                'slotId' => $this->integer()->notNull(),
                'breakpoint' => $this->smallInteger()->notNull(),
                'samples' => $this->integer()->notNull()->defaultValue(0),

                // A sum of heights over enough page views overflows a 32-bit column sooner than
                // feels plausible: 20,000 px times 200,000 samples is already past it.
                'heightSum' => $this->bigInteger()->notNull()->defaultValue(0),

                'heightMax' => $this->integer()->notNull()->defaultValue(0),

                // The heights themselves, up to the sample target, so the reservation can be a
                // median rather than a mean. Numbers only — nothing about who sent them.
                'heights' => $this->text(),

                'aboveFold' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, MetricRecord::TABLE, ['slotId', 'breakpoint'], true);

            // CASCADE, because a measurement of a slot that no longer exists is not a measurement
            // of anything.
            $this->addForeignKey(null, MetricRecord::TABLE, ['slotId'], SlotRecord::TABLE, ['id'], 'CASCADE', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(MetricRecord::TABLE);
        $this->dropTableIfExists(SlotRecord::TABLE);

        return true;
    }
}
