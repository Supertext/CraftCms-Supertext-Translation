<?php

declare(strict_types=1);

namespace supertext\crafttranslation\sdk;

/**
 * Version-neutral facts about this SDK. Everything that talks to the API lives in a versioned namespace:
 * supertext\crafttranslation\sdk\V1 (a later API version gets supertext\crafttranslation\sdk\V2 next to it).
 */
final class Supertext
{
    /** This SDK's version (sent in version reports). The same number in every Supertext SDK. */
    public const SDK_VERSION = '0.2.1';

    private function __construct()
    {
    }
}
