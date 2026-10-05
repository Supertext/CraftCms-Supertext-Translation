<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\web\assets\translate;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/** The Supertext box on the entry edit page. */
class TranslateAsset extends AssetBundle
{
    /** Strings the JavaScript translates with Craft.t('supertext-translation', …). */
    public const MESSAGES = [
        'From',
        'Into',
        'Already translated',
        'Translated with Supertext on {date}',
        'Overwrite existing translations',
        'Changes made to those translations are replaced by a new translation. Leave this off to translate only the sites without their own text.',
        'Supertext translates the saved version of this site. Save your changes first.',
        'Translate',
        'Translating…',
        'translated',
        'already translated, skipped',
        'Open',
        'Supertext is not set up yet: an administrator needs to add the API key in the plugin settings.',
        'This entry exists in no other site you can edit.',
        'Choose at least one site.',
    ];

    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['translate.js'];
        $this->css = ['translate.css'];
        parent::init();
    }
}
