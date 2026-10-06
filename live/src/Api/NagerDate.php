<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

/**
 * Public holidays for about a hundred countries from date.nager.at - free, no key.
 */
final class NagerDate
{
    private const string ENDPOINT = 'https://date.nager.at/api/v3/PublicHolidays/';

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * Holidays that fall between two dates, inclusive.
     *
     * @return list<array{date: string, name: string, local_name: string, nationwide: bool}>|null
     *         null when the service does not cover the country
     * @throws ApiUnavailable
     */
    public function between(string $countryCode, string $start, string $end): ?array
    {
        $found = [];

        for ($year = (int) \substr($start, 0, 4); $year <= (int) \substr($end, 0, 4); ++$year) {
            try {
                $rows = $this->http->getJson(self::ENDPOINT . $year . '/' . \rawurlencode(\strtoupper($countryCode)));
            } catch (ApiUnavailable $e) {
                if ($e->isNotFound() || $e->status === 204) {
                    return null;
                }

                throw $e;
            }

            /** @var array<int, array<string, mixed>> $rows */
            foreach ($rows as $row) {
                $date = (string) ($row['date'] ?? '');

                if ($date >= $start && $date <= $end) {
                    $found[] = [
                        'date' => $date,
                        'name' => (string) ($row['name'] ?? ''),
                        'local_name' => (string) ($row['localName'] ?? ''),
                        'nationwide' => (bool) ($row['global'] ?? true),
                    ];
                }
            }
        }

        \usort($found, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $found;
    }
}
