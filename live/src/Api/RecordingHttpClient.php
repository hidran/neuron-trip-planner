<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * Writes every real response to disk, one file per request.
 *
 * bin/record-fixtures.php runs the API clients through this decorator once,
 * online. The files it leaves in tests/fixtures/recorded/ are what
 * ReplayHttpClient serves back, so the shape the tests check is the shape the
 * real service returned on the day you recorded it - not what anyone
 * remembered about it.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly string $directory,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $response = $this->inner->request($request);

        if (!\is_dir($this->directory)) {
            \mkdir($this->directory, 0775, true);
        }

        \file_put_contents(
            $this->directory . '/' . self::keyFor($request->uri) . '.json',
            \json_encode(
                ['url' => $request->uri, 'status' => $response->statusCode, 'body' => $response->body],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            ),
        );

        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        return $this->inner->stream($request);
    }

    /**
     * host_hash: readable in a directory listing, stable across runs.
     */
    public static function keyFor(string $uri): string
    {
        return \preg_replace('/[^a-z0-9.-]/', '', \strtolower((string) \parse_url($uri, \PHP_URL_HOST)))
            . '_' . \substr(\sha1($uri), 0, 10);
    }
}
