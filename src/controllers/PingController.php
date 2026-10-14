<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\controllers;

use craft\web\Controller;
use supertext\crafttranslation\api\Reporting;
use supertext\crafttranslation\Plugin;
use yii\web\Response;

/**
 * GET actions/supertext-translation/ping?challenge=…   public (no login, no session, no CSRF)
 *
 * The Supertext Commando Center checks that the site is reachable: 200 {"ok":true,"plugin":…,"challenge":…}
 * for a valid challenge, 400 otherwise, 404 while version reports are off (the SDK's pingResponse(),
 * sent as it is, with Cache-Control: no-store). Nothing else: no versions, settings or data.
 */
class PingController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $request = $this->request;
        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        if (!$request->getIsGet()) {
            $response->setStatusCode(405);
            $response->getHeaders()->set('Allow', 'GET')->set('Cache-Control', 'no-store');
            $response->data = '';

            return $response;
        }

        $challenge = $request->getQueryParam('challenge');
        $ping = Reporting::ping(\is_string($challenge) ? $challenge : null, Plugin::getInstance()->getReporting()->enabled());
        $response->setStatusCode($ping->status);
        foreach ($ping->headers as $name => $value) {
            $response->getHeaders()->set($name, $value);
        }
        $response->data = $ping->body;

        return $response;
    }
}
