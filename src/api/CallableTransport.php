<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\api;

use supertext\crafttranslation\sdk\Http\HttpRequest;
use supertext\crafttranslation\sdk\Http\HttpResponse;
use supertext\crafttranslation\sdk\Http\HttpTransport;
use supertext\crafttranslation\sdk\Http\TransportException;

/**
 * Sends the Supertext SDK's requests through a callable: Craft's Guzzle client in the
 * plugin (so Craft's proxy settings apply), a fake in tests. Every HTTP answer, 4xx and
 * 5xx included, goes back to the SDK; only a missing answer is an exception.
 */
final class CallableTransport implements HttpTransport
{
    /** @var callable(string, string, array<string, string>, ?string): array{status: int, body: string, headers?: array<string, string>} */
    private $send;

    /** @param callable(string $method, string $url, array<string, string> $headers, ?string $body): array{status: int, body: string, headers?: array<string, string>} $send */
    public function __construct(callable $send)
    {
        $this->send = $send;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $response = ($this->send)($request->method, $request->url, $request->headers, $request->body);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return new HttpResponse($response['status'], $response['body'], $response['headers'] ?? []);
    }
}
