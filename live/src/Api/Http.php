<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

use JsonException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;

/**
 * The one place that talks HTTP to the outside world.
 *
 * Every API client in this package takes an instance of this class, and this
 * class takes any Neuron HttpClientInterface. In production that is cURL
 * behind a cache; in the tests it is a scripted fake; when recording fixtures
 * it is a decorator that writes real responses to disk. The API clients never
 * know which.
 */
final class Http
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $userAgent = 'neuron-trip-planner-live/1.0 (+https://github.com/hidran/neuron-trip-planner)',
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws ApiUnavailable
     */
    public function getJson(string $url, array $query = [], array $headers = []): array
    {
        $query = \array_filter($query, static fn (mixed $v): bool => $v !== null);

        if ($query !== []) {
            $url .= (\str_contains($url, '?') ? '&' : '?') . \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        }

        return $this->send(HttpRequest::get($url, $this->headers($headers)));
    }

    /**
     * @param array<string, scalar> $fields
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws ApiUnavailable
     */
    public function postForm(string $url, array $fields, array $headers = []): array
    {
        return $this->send(new HttpRequest(
            HttpMethod::POST,
            $url,
            $this->headers(['Content-Type' => 'application/x-www-form-urlencoded', ...$headers]),
            \http_build_query($fields, '', '&', \PHP_QUERY_RFC3986),
        ));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws ApiUnavailable
     */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->send(HttpRequest::post($url, $body, $this->headers($headers)));
    }

    /**
     * @return array<string, mixed>
     */
    private function send(HttpRequest $request): array
    {
        $host = (string) \parse_url($request->uri, \PHP_URL_HOST);

        try {
            $response = $this->client->request($request);
        } catch (HttpException $e) {
            // A client may throw on a non-2xx answer as well as on a dead socket; keep the status when there is one.
            $status = $e->response?->statusCode;

            throw new ApiUnavailable(
                $status !== null ? "{$host} answered HTTP {$status}" : "{$host} is unreachable: {$e->getMessage()}",
                $status,
                $e,
            );
        }

        if (!$response->isSuccessful()) {
            throw new ApiUnavailable("{$host} answered HTTP {$response->statusCode}", $response->statusCode);
        }

        try {
            return $response->json();
        } catch (JsonException $e) {
            throw new ApiUnavailable("{$host} returned something that is not JSON", $response->statusCode, $e);
        }
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function headers(array $extra): array
    {
        return ['Accept' => 'application/json', 'User-Agent' => $this->userAgent, ...$extra];
    }
}
