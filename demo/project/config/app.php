<?php

use craft\helpers\App;

return [
    'id' => App::env('CRAFT_APP_ID') ?: 'supertext-craft-demo',
    'modules' => [
        'supertext-demo' => \modules\supertextdemo\Module::class,
    ],
    'bootstrap' => ['supertext-demo'],
];
