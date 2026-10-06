<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

/**
 * Facts about a country from restcountries.com - free, no key.
 */
final class RestCountries
{
    private const string ENDPOINT = 'https://restcountries.com/v3.1/alpha/';

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * @param string $code ISO 3166-1 alpha-2, e.g. "PT"
     * @return array{country: string, official_name: string, capital: string, region: string, subregion: string, currencies: list<array{code: string, name: string, symbol: string}>, languages: list<string>, calling_code: string, driving_side: string, timezones: list<string>, population: int}|null
     *         null when the service does not know the code
     * @throws ApiUnavailable
     */
    public function byCode(string $code): ?array
    {
        try {
            $data = $this->http->getJson(self::ENDPOINT . \rawurlencode(\strtoupper($code)), [
                'fields' => 'name,capital,region,subregion,currencies,languages,idd,car,timezones,population',
            ]);
        } catch (ApiUnavailable $e) {
            if ($e->isNotFound() || $e->status === 400) {
                return null;
            }

            throw $e;
        }

        // v3.1 answers an object for /alpha/{code}; older versions answered a list.
        /** @var array<string, mixed> $c */
        $c = \array_is_list($data) ? ($data[0] ?? []) : $data;

        if ($c === []) {
            return null;
        }

        $currencies = [];
        /** @var array<string, array{name?: string, symbol?: string}> $raw */
        $raw = $c['currencies'] ?? [];
        foreach ($raw as $currencyCode => $info) {
            $currencies[] = ['code' => (string) $currencyCode, 'name' => (string) ($info['name'] ?? ''), 'symbol' => (string) ($info['symbol'] ?? '')];
        }

        /** @var array{root?: string, suffixes?: list<string>} $idd */
        $idd = $c['idd'] ?? [];
        $suffixes = $idd['suffixes'] ?? [];
        // "+1" with dozens of suffixes is a shared area code; "+351" with one suffix is the whole number.
        $callingCode = ($idd['root'] ?? '') . (\count($suffixes) === 1 ? $suffixes[0] : '');

        /** @var array{common?: string, official?: string} $name */
        $name = $c['name'] ?? [];
        /** @var list<string> $capital */
        $capital = $c['capital'] ?? [];
        /** @var array<string, string> $languages */
        $languages = $c['languages'] ?? [];
        /** @var array{side?: string} $car */
        $car = $c['car'] ?? [];
        /** @var list<string> $timezones */
        $timezones = $c['timezones'] ?? [];

        return [
            'country' => (string) ($name['common'] ?? ''),
            'official_name' => (string) ($name['official'] ?? ''),
            'capital' => (string) ($capital[0] ?? ''),
            'region' => (string) ($c['region'] ?? ''),
            'subregion' => (string) ($c['subregion'] ?? ''),
            'currencies' => $currencies,
            'languages' => \array_values($languages),
            'calling_code' => $callingCode,
            'driving_side' => (string) ($car['side'] ?? ''),
            'timezones' => \array_slice($timezones, 0, 4),
            'population' => (int) ($c['population'] ?? 0),
        ];
    }
}
