<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\api;

use supertext\crafttranslation\sdk\V1\Languages as SdkLanguages;
use supertext\crafttranslation\sdk\V1\SupertextException as SdkException;

/**
 * Site languages → Supertext language codes, without guessing (the Supertext SDK's rules,
 * the same in every Supertext plugin).
 *
 * Supertext accepts exactly the codes of its `GET features` list: bare sources ("en"); targets
 * regional only for German, English, French, Italian and Portuguese ("de-CH", "en-US", "pt-BR"),
 * with a script for Chinese and Serbian ("zh-Hans", "sr-Latn"), bare for the others ("es", "nl").
 *
 * A site's *Supertext language* (plugin settings) wins; otherwise a site language that equals a
 * Supertext code apart from case and "_"/"-" is used ("de-CH", "fr_ch" → "fr-CH", "es"); anything
 * else is the error `unsupported_language` and nothing is sent. There are no default regions:
 * a site in "de" needs its Supertext language (de-DE, de-CH or de-AT). The same setting serves
 * the source: a mapped "de-CH" is sent as the source "de".
 *
 * Has no Craft dependencies.
 */
final class Languages
{
    /**
     * Every target Supertext offers: code, English name, whether it has formal/informal.
     *
     * @return list<array{code: string, name: string, politeness: bool}>
     */
    public static function targets(): array
    {
        return SdkLanguages::TARGETS;
    }

    /** The Supertext target that equals the site language apart from case and "_"/"-", else null ("de" → null). */
    public static function match(string $siteLanguage): ?string
    {
        return SdkLanguages::matchTarget($siteLanguage);
    }

    /** The target code to send, or null when the site language has no Supertext language (for the settings page). */
    public static function targetOrNull(string $siteLanguage, string $code = ''): ?string
    {
        try {
            return self::target($siteLanguage, $code);
        } catch (SupertextException) {
            return null;
        }
    }

    /**
     * The target code to send: the site's Supertext language, else an exact match.
     *
     * @throws SupertextException reason unsupported_language
     */
    public static function target(string $siteLanguage, string $code = ''): string
    {
        try {
            return SdkLanguages::target($siteLanguage, self::mapping($siteLanguage, $code));
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /**
     * The source code to send: the source form of the site's Supertext language, else of the site language
     * ("en-US" → "en"). Empty stays empty (auto-detect).
     *
     * @throws SupertextException reason unsupported_language
     */
    public static function source(string $siteLanguage, string $code = ''): string
    {
        try {
            return SdkLanguages::source($siteLanguage, self::mapping($siteLanguage, $code));
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /** Whether Supertext offers formal/informal for a target code (null: a code the SDK's list doesn't know). */
    public static function supportsPoliteness(string $target): ?bool
    {
        return SdkLanguages::supportsPoliteness($target);
    }

    /**
     * One site's setting as the SDK's mapping (site language → Supertext code).
     *
     * @return array<string, string>
     */
    private static function mapping(string $siteLanguage, string $code): array
    {
        return trim($code) !== '' && trim($siteLanguage) !== '' ? [$siteLanguage => trim($code)] : [];
    }
}
