<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\models;

use craft\base\Model;
use craft\helpers\App;
use supertext\crafttranslation\api\Languages;
use supertext\crafttranslation\api\SupertextClient;

/**
 * Plugin settings (stored in the project config). The API key is normally an
 * environment variable reference such as `$SUPERTEXT_API_KEY`, so the key itself
 * never ends up in the project config files.
 */
class Settings extends Model
{
    public const SAVE_DRAFT = 'draft';
    public const SAVE_DIRECT = 'direct';

    /** API key or an environment variable reference (`$SUPERTEXT_API_KEY`). */
    public string $apiKey = '$SUPERTEXT_API_KEY';

    /** live, staging or testing. */
    public string $environment = 'live';

    /** Custom API base URL (overrides the environment), may be an env var reference. */
    public string $apiUrl = '';

    /** How translations are saved: as a draft for review (default) or directly. */
    public string $saveMode = self::SAVE_DRAFT;

    /**
     * Per site handle: Supertext language code and tone. Without a code, the site's language is used if it
     * is a Supertext code itself (apart from case and "_"/"-"); otherwise the site has no Supertext language
     * and isn't translated (no default regions, see api/Languages).
     *
     * @var array<string, array{code?: string, politeness?: string}>
     */
    public array $languages = [];

    /** Seconds to wait for one translation. */
    public int $timeout = 180;

    /** "Send version information to Supertext" (SUPERTEXT_VERSION_REPORTS on the server wins). */
    public bool $versionReports = true;

    public function getApiKey(): string
    {
        $value = App::parseEnv($this->apiKey);

        return SupertextClient::normalizeKey(\is_string($value) ? $value : '');
    }

    public function getBaseUrl(): string
    {
        $custom = App::parseEnv($this->apiUrl);

        return SupertextClient::baseUrlFor($this->environment, \is_string($custom) ? $custom : '');
    }

    /** The site's Supertext language as set in the settings ('' if none). */
    public function languageCode(string $siteHandle): string
    {
        return trim((string) ($this->languages[$siteHandle]['code'] ?? ''));
    }

    /**
     * Supertext target code for a site: its Supertext language, else the site's language if it is a Supertext code.
     *
     * @throws \supertext\crafttranslation\api\SupertextException reason unsupported_language
     */
    public function targetCode(string $siteHandle, string $siteLanguage): string
    {
        return Languages::target($siteLanguage, $this->languageCode($siteHandle));
    }

    /** Like targetCode(), but null for a site without a Supertext language (settings page). */
    public function targetCodeOrNull(string $siteHandle, string $siteLanguage): ?string
    {
        return Languages::targetOrNull($siteLanguage, $this->languageCode($siteHandle));
    }

    /**
     * Supertext source code for a site: the source form of its Supertext language or of the site's language ("en-US" → "en").
     *
     * @throws \supertext\crafttranslation\api\SupertextException reason unsupported_language
     */
    public function sourceCode(string $siteHandle, string $siteLanguage): string
    {
        return Languages::source($siteLanguage, $this->languageCode($siteHandle));
    }

    public function politeness(string $siteHandle): string
    {
        $value = (string) ($this->languages[$siteHandle]['politeness'] ?? 'default');

        return \in_array($value, ['more', 'less'], true) ? $value : 'default';
    }

    protected function defineRules(): array
    {
        return [
            [['environment'], 'in', 'range' => ['live', 'staging', 'testing']],
            [['saveMode'], 'in', 'range' => [self::SAVE_DRAFT, self::SAVE_DIRECT]],
            [['timeout'], 'integer', 'min' => 10, 'max' => 1800],
            [['apiKey', 'apiUrl'], 'string'],
            [['versionReports'], 'boolean'],
        ];
    }
}
