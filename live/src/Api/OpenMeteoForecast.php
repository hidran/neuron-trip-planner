<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

/**
 * The real weather forecast from Open-Meteo - free, no key.
 *
 * The climate tool answers "what is this place usually like in March". This
 * one answers "what will it be like next week", and only reaches sixteen days
 * ahead: past that, no forecast exists and the tool must say so rather than
 * make one up.
 */
final class OpenMeteoForecast
{
    public const int HORIZON_DAYS = 16;

    private const string ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * @return list<array{date: string, max_c: float, min_c: float, rain_mm: float, rain_probability_pct: int}>
     * @throws ApiUnavailable
     */
    public function daily(float $latitude, float $longitude, string $start, string $end): array
    {
        $data = $this->http->getJson(self::ENDPOINT, [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max',
            'timezone' => 'auto',
            'start_date' => $start,
            'end_date' => $end,
        ]);

        /** @var array{time?: list<string>, temperature_2m_max?: list<float|null>, temperature_2m_min?: list<float|null>, precipitation_sum?: list<float|null>, precipitation_probability_max?: list<int|null>} $d */
        $d = $data['daily'] ?? [];
        $days = [];

        foreach ($d['time'] ?? [] as $i => $date) {
            $days[] = [
                'date' => $date,
                'max_c' => (float) ($d['temperature_2m_max'][$i] ?? 0),
                'min_c' => (float) ($d['temperature_2m_min'][$i] ?? 0),
                'rain_mm' => (float) ($d['precipitation_sum'][$i] ?? 0),
                'rain_probability_pct' => (int) ($d['precipitation_probability_max'][$i] ?? 0),
            ];
        }

        return $days;
    }
}
