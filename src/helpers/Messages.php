<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\helpers;

use Craft;
use supertext\crafttranslation\api\SupertextException;

/** Shows errors from the API client (which only speaks English) in the user's control panel language. */
final class Messages
{
    public const SIGNUP_URL = 'https://www.supertext.com/person/en/account/signin';
    public const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

    public static function of(\Throwable $e): string
    {
        if (!$e instanceof SupertextException || $e->reason === '') {
            return $e->getMessage();
        }

        $params = array_values($e->args) + ['signupUrl' => self::SIGNUP_URL, 'url' => self::API_KEY_URL];
        $text = match ($e->reason) {
            'no_file_id' => Craft::t('supertext-translation', 'Supertext did not return a file id.'),
            'translation_failed' => Craft::t('supertext-translation', 'Supertext could not translate the document.'),
            'limit_exceeded' => Craft::t('supertext-translation', 'Your Supertext translation limit is exceeded. Please upgrade your subscription.'),
            'deleted' => Craft::t('supertext-translation', 'The document was deleted at Supertext before it could be downloaded.'),
            'timeout' => Craft::t('supertext-translation', 'Timed out waiting for the Supertext translation.'),
            'empty' => Craft::t('supertext-translation', 'The translated document was empty.'),
            'no_api_key' => self::noApiKey(),
            'unreachable' => Craft::t('supertext-translation', 'Could not reach Supertext: {0}', $params),
            'auth_failed' => Craft::t('supertext-translation', 'Authentication failed. Please check the Supertext API key. No Supertext account yet? Create one at {signupUrl}. Generate your API key at {url} (requires the Admin role).', $params),
            'not_found' => Craft::t('supertext-translation', 'The requested Supertext resource was not found.'),
            'too_large' => Craft::t('supertext-translation', 'The content is too large for Supertext to translate in one go.'),
            'rate_limited' => Craft::t('supertext-translation', 'Too many requests to Supertext. Please try again shortly.'),
            'unavailable' => Craft::t('supertext-translation', 'The Supertext service is currently unavailable.'),
            'http_error' => Craft::t('supertext-translation', 'Supertext answered with HTTP {0}.', $params),
            default => null,
        };

        if ($text === null) {
            return $e->getMessage();
        }

        return $e->detail !== '' ? $text . ' (' . $e->detail . ')' : $text;
    }

    public static function noApiKey(): string
    {
        return Craft::t('supertext-translation', 'No Supertext API key is configured. Set it in the plugin settings (usually as $SUPERTEXT_API_KEY). No Supertext account yet? Create one at {signupUrl}. Generate your API key at {url} (requires the Admin role).', [
            'signupUrl' => self::SIGNUP_URL,
            'url' => self::API_KEY_URL,
        ]);
    }
}
