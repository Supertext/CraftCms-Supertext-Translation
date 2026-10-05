<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use supertext\crafttranslation\elements\actions\TranslateWithSupertext;
use supertext\crafttranslation\models\Settings;
use supertext\crafttranslation\services\Translator;
use supertext\crafttranslation\web\assets\translate\TranslateAsset;
use yii\base\Event;

/**
 * Supertext Translation: translates entries into the plugin's other sites with Supertext AI.
 *
 * - Entry edit page: a "Supertext" box in the sidebar (choose sites, translate)
 * - Entry index: a "Translate with Supertext" action (queue jobs, missing translations only)
 * - Settings: API key, environment, languages and tone, Test connection
 * - Console: php craft supertext-translation/translate
 *
 * @property-read Translator $translator
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION = 'supertext-translation:translate';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => ['translator' => Translator::class],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'supertext\\crafttranslation\\console\\controllers';
        }

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => 'Supertext',
                'permissions' => [
                    self::PERMISSION => ['label' => Craft::t('supertext-translation', 'Translate entries with Supertext')],
                ],
            ];
        });

        Event::on(Entry::class, Element::EVENT_REGISTER_ACTIONS, function (RegisterElementActionsEvent $event) {
            if (Craft::$app->getIsMultiSite() && Craft::$app->getUser()->checkPermission(self::PERMISSION)) {
                $event->actions[] = TranslateWithSupertext::class;
            }
        });

        Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function (DefineHtmlEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $html = $this->sidebarHtml($entry);
            if ($html !== '') {
                $event->html = $html . $event->html;
            }
        });
    }

    public function getTranslator(): Translator
    {
        /** @var Translator */
        return $this->get('translator');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('supertext-translation/_settings', [
            'settings' => $this->getSettings(),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /** The Supertext box on the entry edit page (canonical entries in a multi-site install only). */
    private function sidebarHtml(Entry $entry): string
    {
        $request = Craft::$app->getRequest();
        if (!$request->getIsCpRequest() || $request->getIsConsoleRequest() || !$entry->id) {
            return '';
        }
        if ($entry->getIsRevision() || ($entry->getIsDraft() && !$entry->isProvisionalDraft) || $entry->getIsUnpublishedDraft()) {
            return '';
        }
        if (!Craft::$app->getUser()->checkPermission(self::PERMISSION) || \count($entry->getSupportedSites()) < 2) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(TranslateAsset::class);
        $view->registerTranslations('supertext-translation', TranslateAsset::MESSAGES);

        return $view->renderTemplate('supertext-translation/_sidebar', [
            'entryId' => $entry->getCanonicalId(),
            'siteId' => $entry->siteId,
        ]);
    }
}
