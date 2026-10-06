<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * Serves what RecordingHttpClient saved. A request nobody recorded is an
 * error, never a silent network call: a test must not pass because the
 * internet happened to be up.
 */
final class ReplayHttpClient implements HttpClientInterface
{
    public function __construct(private readonly string $directory)
    {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $file = $this->directory . '/' . RecordingHttpClient::keyFor($request->uri) . '.json';

        if (!\is_file($file)) {
            throw new HttpException("No recorded response for {$request->uri}");
        }

        /** @var array{status: int, body: string} $saved */
        $saved = \json_decode((string) \file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);

        return new HttpResponse($saved['status'], $saved['body']);
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new HttpException('Replay does not stream.');
    }
}
