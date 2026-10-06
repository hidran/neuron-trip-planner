<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tests\Support;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * The network, replaced by a list of routes.
 *
 * A route is a regular expression matched against "METHOD url" and the
 * fixture file (or status) that answers it. A request no route matches is an
 * error, so a test can never pass by quietly reaching the real internet.
 * Every request is kept, so a test can assert what was - and was not - called.
 */
final class ScriptedHttp implements HttpClientInterface
{
    /** @var list<array{pattern: string, status: int, body: string}> */
    private array $routes = [];

    /** @var list<string> */
    public array $requests = [];

    public function route(string $pattern, string $fixture, int $status = 200): self
    {
        $this->routes[] = [
            'pattern' => $pattern,
            'status' => $status,
            'body' => $status >= 400 && $fixture === '' ? '{}' : self::fixture($fixture),
        ];

        return $this;
    }

    public function fail(string $pattern, int $status): self
    {
        return $this->route($pattern, '', $status);
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $line = $request->method->value . ' ' . $request->uri;
        $this->requests[] = $line;

        foreach ($this->routes as $route) {
            if (\preg_match($route['pattern'], $line) === 1) {
                return new HttpResponse($route['status'], $route['body']);
            }
        }

        throw new HttpException("ScriptedHttp: no route for {$line}");
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new HttpException('ScriptedHttp does not stream.');
    }

    public function called(string $needle): int
    {
        return \count(\array_filter($this->requests, static fn (string $r): bool => \str_contains($r, $needle)));
    }

    public static function fixture(string $name): string
    {
        $file = __DIR__ . "/../fixtures/{$name}.json";

        return \is_file($file) ? (string) \file_get_contents($file) : throw new \LogicException("Missing fixture {$name}");
    }
}
