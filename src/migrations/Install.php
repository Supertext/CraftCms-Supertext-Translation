<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\migrations;

use craft\db\Migration;
use craft\db\Table;
use supertext\crafttranslation\records\TranslationRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(TranslationRecord::TABLE)) {
            $this->createTable(TranslationRecord::TABLE, [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer()->notNull(),
                'sourceSiteId' => $this->integer()->notNull(),
                'targetSiteId' => $this->integer()->notNull(),
                'userId' => $this->integer(),
                'dateTranslated' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, TranslationRecord::TABLE, ['elementId', 'targetSiteId'], true);
            $this->addForeignKey(null, TranslationRecord::TABLE, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
            $this->addForeignKey(null, TranslationRecord::TABLE, ['sourceSiteId'], Table::SITES, ['id'], 'CASCADE');
            $this->addForeignKey(null, TranslationRecord::TABLE, ['targetSiteId'], Table::SITES, ['id'], 'CASCADE');
            $this->addForeignKey(null, TranslationRecord::TABLE, ['userId'], Table::USERS, ['id'], 'SET NULL');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(TranslationRecord::TABLE);

        return true;
    }
}
