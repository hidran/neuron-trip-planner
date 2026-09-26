<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Climate;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use NeuronBook\TripPlanner\Geo\Place;
use Uri\Rfc3986\Uri;

/**
 * Real observed weather from the Open-Meteo archive, anywhere on Earth -
 * free, no API key.
 *
 * One year of daily observations is reduced to twelve monthly rows and
 * cached on disk: last year's weather does not change, and a tool the model
 * may call once per candidate city should not cost a round trip every time.
 */
final class OpenMeteoClimate implements ClimateSource
{
    private const string ENDPOINT = 'https://archive-api.open-meteo.com/v1/archive';

    public function __construct(
        private readonly string $cacheDir,
        private readonly int $year,
    ) {
    }

    public function monthly(Place $place): array
    {
        $cache = "{$this->cacheDir}/climate-{$place->id}-{$this->year}.json";

        if (\is_file($cache)) {
            /** @var array<string, array{avg_max_c: float, rain_mm: int, rainy_days: int, humidity_pct: int}> */
            return \json_decode((string) \file_get_contents($cache), true, flags: \JSON_THROW_ON_ERROR);
        }

        $url = new Uri(self::ENDPOINT)->withQuery(\http_build_query([
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
            'start_date' => "{$this->year}-01-01",
            'end_date' => "{$this->year}-12-31",
            'daily' => 'temperature_2m_max,precipitation_sum,relative_humidity_2m_mean',
            'timezone' => 'auto',
        ]));

        /** @var array{time: list<string>, temperature_2m_max: list<float|null>, precipitation_sum: list<float|null>, relative_humidity_2m_mean: list<float|null>} $daily */
        $daily = new CurlHttpClient(timeout: 20.0)->request(HttpRequest::get($url->toString()))->json()['daily'];

        $byMonth = [];
        foreach ($daily['time'] as $i => $day) {
            $m = (int) \substr($day, 5, 2);
            $byMonth[$m]['t'][] = (float) ($daily['temperature_2m_max'][$i] ?? 0);
            $byMonth[$m]['p'][] = (float) ($daily['precipitation_sum'][$i] ?? 0);
            $byMonth[$m]['h'][] = (float) ($daily['relative_humidity_2m_mean'][$i] ?? 0);
        }

        $average = static fn (array $values): float => \array_sum($values) / \max(1, \count($values));

        $result = [];
        foreach ($byMonth as $m => $v) {
            $result[self::MONTHS[$m]] = [
                // PHP 8.5: the pipe operator reads left to right, in the order the data flows.
                'avg_max_c' => $v['t'] |> $average |> (static fn (float $x): float => \round($x, 1)),
                'rain_mm' => (int) \round(\array_sum($v['p'])),
                'rainy_days' => \count(\array_filter($v['p'], static fn (float $p): bool => $p >= 1.0)),
                'humidity_pct' => (int) \round($average($v['h'])),
            ];
        }

        if (!\is_dir($this->cacheDir)) {
            \mkdir($this->cacheDir, 0775, true);
        }
        \file_put_contents($cache, \json_encode($result, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

        return $result;
    }
}
