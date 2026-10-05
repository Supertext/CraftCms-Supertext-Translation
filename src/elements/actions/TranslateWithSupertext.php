<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Queue;
use supertext\crafttranslation\jobs\TranslateEntryJob;

/**
 * Entry index action: queues a translation of each selected entry from the site the
 * index shows into all its other sites. Sites that already have their own text are
 * skipped (use the entry's Supertext box to overwrite them).
 */
class TranslateWithSupertext extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('supertext-translation', 'Translate with Supertext');
    }

    public function getConfirmationMessage(): ?string
    {
        return Craft::t('supertext-translation', 'Translate the selected entries into all their other sites? Sites that already have their own text are skipped.');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        $count = 0;
        foreach ($query->all() as $entry) {
            $siteIds = [];
            foreach ($entry->getSupportedSites() as $site) {
                $siteId = \is_array($site) ? (int) $site['siteId'] : (int) $site;
                if ($siteId !== (int) $entry->siteId) {
                    $siteIds[] = $siteId;
                }
            }
            if ($siteIds === []) {
                continue;
            }
            Queue::push(new TranslateEntryJob([
                'entryId' => $entry->getCanonicalId(),
                'sourceSiteId' => (int) $entry->siteId,
                'targetSiteIds' => $siteIds,
                'userId' => $user?->id,
            ]));
            $count++;
        }

        $this->setMessage(Craft::t('supertext-translation', '{count, plural, =1{One entry is} other{# entries are}} being translated in the background.', ['count' => $count]));

        return true;
    }
}
