<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\migrations;

use craft\db\Migration;
use supertext\crafttranslation\services\Reporting;

/** Adds the table supertext_state (the version reporter's state and the latest API key check). */
class m261014_000000_supertext_state extends Migration
{
    public function safeUp(): bool
    {
        self::createStateTable($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Reporting::STATE_TABLE);

        return true;
    }

    public static function createStateTable(Migration $migration): void
    {
        if (!$migration->db->tableExists(Reporting::STATE_TABLE)) {
            $migration->createTable(Reporting::STATE_TABLE, [
                'name' => $migration->string(64)->notNull(),
                'value' => $migration->text(),
                'PRIMARY KEY([[name]])',
            ]);
        }
    }
}
