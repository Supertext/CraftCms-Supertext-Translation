<?php

namespace modules\supertextdemo;

use Craft;
use craft\console\Application as ConsoleApplication;

/** Demo only: `php craft supertext-demo/setup` (see SetupController). Not part of the plugin. */
class Module extends \yii\base\Module
{
    public function init(): void
    {
        Craft::setAlias('@modules/supertextdemo', __DIR__);
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'modules\\supertextdemo\\console';
        }
        parent::init();
    }
}
