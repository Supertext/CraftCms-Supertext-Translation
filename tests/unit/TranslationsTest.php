<?php

namespace supertext\crafttranslation\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every string the plugin translates has a German, French and Italian version with the same placeholders. */
final class TranslationsTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';

    /** @return iterable<string, array{string}> */
    public static function languages(): iterable
    {
        foreach (['de', 'fr', 'it'] as $language) {
            yield $language => [$language];
        }
    }

    #[DataProvider('languages')]
    public function testEveryStringIsTranslated(string $language): void
    {
        $strings = require self::SRC . "/translations/$language/supertext-translation.php";
        $used = self::usedStrings();

        self::assertSame([], array_values(array_diff($used, array_keys($strings))), "missing in $language");
        self::assertSame([], array_values(array_diff(array_keys($strings), $used)), "unused in $language");

        foreach ($strings as $english => $translated) {
            self::assertSame(self::placeholders($english), self::placeholders($translated), "$language: $english");
        }
    }

    public function testJavaScriptStringsAreRegistered(): void
    {
        $js = (string) file_get_contents(self::SRC . '/web/assets/translate/dist/translate.js');
        preg_match_all("/(?<![.\\w])t\\('((?:[^'\\\\]|\\\\.)*)'/", $js, $m);
        $messages = self::assetMessages();

        self::assertNotEmpty($m[1]);
        self::assertSame([], array_values(array_diff(array_unique($m[1]), $messages)));
    }

    /** @return list<string> source strings passed to Craft::t / |t with the plugin's category, and TranslateAsset::MESSAGES */
    private static function usedStrings(): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/translations/') || !preg_match('/\.(php|twig)$/', $path)) {
                continue;
            }
            $code = (string) file_get_contents($path);
            preg_match_all("/Craft::t\\('supertext-translation',\\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $a);
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\\|t\\('supertext-translation'/", $code, $b);
            array_push($found, ...$a[1], ...$b[1]);
        }
        $found = array_map(static fn (string $s) => stripcslashes($s), $found);
        array_push($found, ...self::assetMessages());

        return array_values(array_unique($found));
    }

    /** @return list<string> TranslateAsset::MESSAGES, read from the file (the class needs Craft) */
    private static function assetMessages(): array
    {
        $asset = (string) file_get_contents(self::SRC . '/web/assets/translate/TranslateAsset.php');
        preg_match('/MESSAGES = \[(.*?)\];/s', $asset, $block);
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $block[1], $messages);

        return array_map(static fn (string $s) => stripcslashes($s), $messages[1]);
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{[a-zA-Z0-9]+\}|<\/?[a-z]+|https?:\/\/[^\s"<]+|\$[A-Z_]+|`[^`]+`/', $text, $m);
        $found = $m[0];
        sort($found);

        return $found;
    }
}
