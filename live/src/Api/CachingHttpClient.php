<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * Remembers successful GET answers on disk for a while.
 *
 * Why a trip planner wants this: the same city is looked up again every time
 * the traveller asks for "something different", a resumed trip repeats calls a
 * crashed worker had already made, and most of these services are free
 * community APIs that ask clients to be polite. A cached answer is also a
 * stable demo: the second run of the same request works on a train.
 *
 * Only GET is cached, and only a 2xx answer. A failure is never remembered.
 */
final class CachingHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly string $directory,
        private readonly int $ttlSeconds = 21600,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        if ($request->method !== HttpMethod::GET) {
            return $this->inner->request($request);
        }

        $file = $this->directory . '/' . \sha1($request->uri) . '.json';

        if (\is_file($file) && \filemtime($file) >= \time() - $this->ttlSeconds) {
            /** @var array{status: int, body: string}|null $hit */
            $hit = \json_decode((string) \file_get_contents($file), true);

            if (\is_array($hit)) {
                return new HttpResponse($hit['status'], $hit['body']);
            }
        }

        $response = $this->inner->request($request);

        if ($response->isSuccessful()) {
            if (!\is_dir($this->directory)) {
                \mkdir($this->directory, 0775, true);
            }

            \file_put_contents($file, \json_encode(['url' => $request->uri, 'status' => $response->statusCode, 'body' => $response->body], \JSON_THROW_ON_ERROR), \LOCK_EX);
        }

        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        return $this->inner->stream($request);
    }
}
