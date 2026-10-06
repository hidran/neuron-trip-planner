<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

use Closure;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * Says out loud which online API each tool really called.
 *
 * Switched on with TRIP_TRACE=1. It is the demo's best feature: while the
 * advisor "thinks", the terminal shows the actual requests, status codes and
 * timings, so nobody has to take it on trust that the weather came from a
 * weather service.
 */
final class TracingHttpClient implements HttpClientInterface
{
    /**
     * @param Closure(string): void $out
     */
    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly Closure $out,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $started = \microtime(true);
        $label = $request->method->value . ' ' . self::short($request->uri);

        try {
            $response = $this->inner->request($request);
        } catch (\Throwable $e) {
            ($this->out)(\sprintf("  ↳ %s  FAILED (%s)\n", $label, $e->getMessage()));

            throw $e;
        }

        ($this->out)(\sprintf("  ↳ %s  %d  %d ms\n", $label, $response->statusCode, (int) ((\microtime(true) - $started) * 1000)));

        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        return $this->inner->stream($request);
    }

    private static function short(string $uri): string
    {
        return \strlen($uri) > 110 ? \substr($uri, 0, 107) . '...' : $uri;
    }
}
