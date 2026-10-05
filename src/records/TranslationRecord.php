<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\records;

use craft\db\ActiveRecord;

/**
 * The last Supertext translation of an entry into a site (shown in the Supertext box).
 *
 * @property int $id
 * @property int $elementId
 * @property int $sourceSiteId
 * @property int $targetSiteId
 * @property int|null $userId
 * @property string $dateTranslated
 */
class TranslationRecord extends ActiveRecord
{
    public const TABLE = '{{%supertext_translations}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
