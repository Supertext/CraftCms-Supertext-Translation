<?php

namespace supertext\crafttranslation\tests\unit;

use PHPUnit\Framework\TestCase;
use supertext\crafttranslation\api\Languages;
use supertext\crafttranslation\api\SupertextException;

/** Site languages → Supertext codes: the site's Supertext language, else an exact match, else unsupported_language. */
final class LanguagesTest extends TestCase
{
    public function testASiteLanguageThatIsASupertextCodeIsUsed(): void
    {
        self::assertSame('de-CH', Languages::target('de-CH'));
        self::assertSame('de-CH', Languages::target('de_ch'));
        self::assertSame('fr-CH', Languages::target('FR-ch'));
        self::assertSame('es', Languages::target('es'));
        self::assertSame('zh-Hant', Languages::target('zh_hant'));
    }

    public function testNoDefaultRegions(): void
    {
        foreach (['de', 'en', 'fr', 'es-ES', 'sl-SI', 'zh-CN', 'xx'] as $code) {
            try {
                Languages::target($code);
                self::fail("$code should need a Supertext language");
            } catch (SupertextException $e) {
                self::assertSame('unsupported_language', $e->reason);
                self::assertSame([$code], $e->args);
            }
            self::assertNull(Languages::targetOrNull($code));
        }
    }

    public function testTheSitesSupertextLanguageWins(): void
    {
        self::assertSame('de-AT', Languages::target('de', 'de-AT'));
        self::assertSame('fr-FR', Languages::target('fr-CH', ' fr-FR '));
        self::assertSame('de-CH', Languages::target('de-CH', '  '), 'a blank setting means the site language');
        self::assertSame('xx-NEW', Languages::target('xx', 'xx-NEW'), 'a code the list does not know yet is sent as it is');
    }

    public function testSourcesAreBareAndUseTheSameSetting(): void
    {
        self::assertSame('en', Languages::source('en-US'));
        self::assertSame('en', Languages::source('en'));
        self::assertSame('de', Languages::source('de-CH'));
        self::assertSame('en', Languages::source('en', 'en-US'), 'a mapped target is sent in its source form');
        self::assertSame('de', Languages::source('xx', 'de-CH'));
        self::assertSame('', Languages::source(''));
    }

    public function testAnUnknownSourceIsRefused(): void
    {
        $this->expectException(SupertextException::class);
        Languages::source('xx');
    }

    public function testMatchesForTheSettingsPage(): void
    {
        self::assertSame('it-CH', Languages::match('it_CH'));
        self::assertNull(Languages::match('de'));
        self::assertContains('de-CH', array_column(Languages::targets(), 'code'));
        self::assertContains('es', array_column(Languages::targets(), 'code'));
    }

    public function testKnowsWhichTargetsHaveAFormOfAddress(): void
    {
        self::assertTrue(Languages::supportsPoliteness('de-CH'));
        self::assertFalse(Languages::supportsPoliteness('en-US'));
        self::assertNull(Languages::supportsPoliteness('xx'));
    }
}
