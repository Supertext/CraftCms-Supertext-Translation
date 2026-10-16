<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\api;

use supertext\crafttranslation\pluginhealth\Http\HttpRequest;
use supertext\crafttranslation\pluginhealth\Http\HttpResponse;
use supertext\crafttranslation\pluginhealth\Http\HttpTransport;
use supertext\crafttranslation\pluginhealth\Http\TransportException;

/**
 * Sends Supertext Plugin Health's version reports through the same callable as the SDK's requests
 * (CallableTransport): Craft's Guzzle client in the plugin, a fake in tests. Plugin Health has its own
 * HTTP types; the report's timeout (3 s) is passed as the fifth argument.
 */
final class ReportTransport implements HttpTransport
{
    /** @var callable(string, string, array<string, string>, ?string, float): array{status: int, body: string, headers?: array<string, string>} */
    private $send;

    /** @param callable(string $method, string $url, array<string, string> $headers, ?string $body, float $timeout): array{status: int, body: string, headers?: array<string, string>} $send */
    public function __construct(callable $send)
    {
        $this->send = $send;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $response = ($this->send)($request->method, $request->url, $request->headers, $request->body, $request->timeout);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return new HttpResponse($response['status'], $response['body'], $response['headers'] ?? []);
    }
}
