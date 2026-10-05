<?php

namespace justinholtweb\bed\migrations;

use craft\db\Migration;
use justinholtweb\bed\records\MetricRecord;

/**
 * Keeps each slot's measured heights, so a reservation can be their median (5.0.1).
 *
 * A mean is moved as far as one bad sample likes: a single visitor reporting 20,000 px could fix
 * a permanent gap in the page. Existing rows have no list and keep their mean until they are
 * purged and measured again.
 */
class m261005_000000_metric_heights extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(MetricRecord::TABLE, 'heights')) {
            $this->addColumn(MetricRecord::TABLE, 'heights', $this->text()->after('heightMax'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(MetricRecord::TABLE, 'heights')) {
            $this->dropColumn(MetricRecord::TABLE, 'heights');
        }

        return true;
    }
}
