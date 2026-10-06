<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

/**
 * A risk score per country from travel-advisory.info - free, no key.
 *
 * The score is an aggregate of several governments' advisories on a 0 to 5
 * scale. It is a signal to read, not a verdict: the tool says so, and so does
 * the prompt of every agent that uses it.
 */
final class TravelAdvisory
{
    private const string ENDPOINT = 'https://www.travel-advisory.info/api';

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * @return array{country: string, score: float, level: string, message: string, updated: string, url: string}|null
     *         null when the country has no entry
     * @throws ApiUnavailable
     */
    public function forCountry(string $countryCode): ?array
    {
        $code = \strtoupper($countryCode);
        $data = $this->http->getJson(self::ENDPOINT, ['countrycode' => $code]);

        /** @var array{name?: string, advisory?: array{score?: float|int, message?: string, updated?: string, source?: string}}|null $row */
        $row = $data['data'][$code] ?? null;

        if ($row === null || !isset($row['advisory']['score'])) {
            return null;
        }

        $score = (float) $row['advisory']['score'];

        return [
            'country' => (string) ($row['name'] ?? $code),
            'score' => $score,
            'level' => match (true) {
                $score < 2.5 => 'low risk',
                $score < 3.5 => 'medium risk',
                $score < 4.5 => 'high risk',
                default => 'extreme risk',
            },
            'message' => (string) ($row['advisory']['message'] ?? ''),
            'updated' => (string) ($row['advisory']['updated'] ?? ''),
            'url' => (string) ($row['advisory']['source'] ?? ''),
        ];
    }
}
