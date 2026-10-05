<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\controllers;

use Craft;
use craft\web\Controller;
use supertext\crafttranslation\api\SupertextException;
use supertext\crafttranslation\Plugin;
use yii\web\Response;

/**
 * Control panel actions:
 *
 *   GET  actions/supertext-translation/translate/info?entryId=&siteId=   sites of the entry
 *   POST actions/supertext-translation/translate/entry                    { entryId, sourceSiteId, targetSiteIds[], overwrite }
 *   POST actions/supertext-translation/translate/test-connection          admins
 */
class TranslateController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        if ($action->id === 'test-connection') {
            $this->requireAdmin(false);
        } else {
            $this->requirePermission(Plugin::PERMISSION);
        }

        return true;
    }

    public function actionInfo(): Response
    {
        $request = Craft::$app->getRequest();
        $entryId = (int) $request->getRequiredQueryParam('entryId');
        $siteId = (int) $request->getRequiredQueryParam('siteId');
        $translator = Plugin::getInstance()->getTranslator();
        $entry = $translator->entryInSite($entryId, $siteId);
        if (!$entry) {
            return $this->asFailure(Craft::t('supertext-translation', 'The entry was not found.'));
        }

        return $this->asJson($translator->describe($entry, Craft::$app->getUser()->getIdentity()) + [
            'configured' => Plugin::getInstance()->getSettings()->getApiKey() !== '',
        ]);
    }

    public function actionEntry(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $targets = array_map('intval', (array) $request->getBodyParam('targetSiteIds', []));
        if ($targets === []) {
            return $this->asFailure(Craft::t('supertext-translation', 'Choose at least one site.'));
        }

        try {
            $results = Plugin::getInstance()->getTranslator()->translate(
                (int) $request->getRequiredBodyParam('entryId'),
                (int) $request->getRequiredBodyParam('sourceSiteId'),
                $targets,
                (bool) $request->getBodyParam('overwrite', false),
                Craft::$app->getUser()->getIdentity(),
            );
        } catch (SupertextException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asJson(['results' => $results]);
    }

    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        try {
            Plugin::getInstance()->getTranslator()->testConnection();
        } catch (SupertextException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('supertext-translation', 'Connected. The API key works.'));
    }
}
