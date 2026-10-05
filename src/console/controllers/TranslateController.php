<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use supertext\crafttranslation\api\SupertextException;
use supertext\crafttranslation\Plugin;
use supertext\crafttranslation\services\Translator;
use yii\console\ExitCode;

/**
 * php craft supertext-translation/translate <entryId> --from=en --to=de,fr [--overwrite]
 * php craft supertext-translation/translate/check
 */
class TranslateController extends Controller
{
    public $defaultAction = 'index';

    /** Handle of the source site (default: the primary site). */
    public ?string $from = null;

    /** Comma-separated target site handles (default: every other site of the entry). */
    public ?string $to = null;

    /** Replace translations that already exist. */
    public bool $overwrite = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'index' ? ['from', 'to', 'overwrite'] : []);
    }

    public function actionIndex(int $entryId): int
    {
        $sites = Craft::$app->getSites();
        $source = $this->from ? $sites->getSiteByHandle($this->from) : $sites->getPrimarySite();
        if (!$source) {
            $this->stderr("Unknown site: {$this->from}\n", Console::FG_RED);

            return ExitCode::USAGE;
        }
        $translator = Plugin::getInstance()->getTranslator();
        $entry = $translator->entryInSite($entryId, $source->id);
        if (!$entry) {
            $this->stderr("Entry {$entryId} not found in site {$source->handle}.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $targets = [];
        if ($this->to) {
            foreach (array_filter(array_map('trim', explode(',', $this->to))) as $handle) {
                $site = $sites->getSiteByHandle($handle);
                if (!$site) {
                    $this->stderr("Unknown site: {$handle}\n", Console::FG_RED);

                    return ExitCode::USAGE;
                }
                $targets[] = $site->id;
            }
        } else {
            foreach ($translator->describe($entry)['sites'] as $site) {
                if (!$site['isSource']) {
                    $targets[] = $site['id'];
                }
            }
        }

        $failed = false;
        foreach ($translator->translate($entryId, $source->id, $targets, $this->overwrite) as $result) {
            $line = "{$result['site']}: {$result['status']}" . (isset($result['message']) ? " ({$result['message']})" : '');
            $failed = $failed || $result['status'] === Translator::STATUS_ERROR;
            $this->stdout($line . "\n", $result['status'] === Translator::STATUS_ERROR ? Console::FG_RED : Console::FG_GREEN);
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /** Checks the API key against the Supertext API (cost-free). */
    public function actionCheck(): int
    {
        try {
            Plugin::getInstance()->getTranslator()->testConnection();
        } catch (SupertextException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("Connected to " . Plugin::getInstance()->getSettings()->getBaseUrl() . ". The API key works.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
