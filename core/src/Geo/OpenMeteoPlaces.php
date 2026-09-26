<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Geo;

use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use Uri\Rfc3986\Uri;

/**
 * Worldwide geocoding from Open-Meteo - free, no API key.
 *
 * Every place a search returns is remembered in a small JSON file, so that a
 * later process - a resume two days on, an HTTP request on another worker -
 * can still turn the place ID the advisor chose back into coordinates.
 */
final class OpenMeteoPlaces implements PlaceDirectory
{
    private const string ENDPOINT = 'https://geocoding-api.open-meteo.com/v1/search';

    public function __construct(private readonly string $storeFile)
    {
    }

    public function search(string $name, ?string $countryCode = null): array
    {
        // PHP 8.5: the URI extension builds and validates the request URL.
        $url = new Uri(self::ENDPOINT)->withQuery(\http_build_query(\array_filter([
            'name' => $name,
            'countryCode' => $countryCode !== null ? \strtoupper($countryCode) : null,
            'count' => 5,
            'language' => 'en',
            'format' => 'json',
        ])));

        $data = new CurlHttpClient(timeout: 15.0)->request(HttpRequest::get($url->toString()))->json();

        /** @var list<array<string, mixed>> $rows */
        $rows = $data['results'] ?? [];

        $places = \array_map(static fn (array $r): Place => new Place(
            id: (int) $r['id'],
            name: (string) $r['name'],
            country: (string) ($r['country'] ?? ''),
            countryCode: (string) ($r['country_code'] ?? ''),
            latitude: (float) $r['latitude'],
            longitude: (float) $r['longitude'],
            region: (string) ($r['admin1'] ?? ''),
            population: (int) ($r['population'] ?? 0),
        ), $rows);

        // Most populous first: "Kyoto" should mean the city of 1.4 million, not the village.
        \usort($places, static fn (Place $a, Place $b): int => $b->population <=> $a->population);

        $this->remember($places);

        return $places;
    }

    public function find(int $id): ?Place
    {
        $row = $this->load()[(string) $id] ?? null;

        return $row === null ? null : Place::fromArray($row);
    }

    /**
     * @param list<Place> $places
     */
    private function remember(array $places): void
    {
        if ($places === []) {
            return;
        }

        $all = $this->load();
        foreach ($places as $place) {
            $all[(string) $place->id] = $place->toArray();
        }

        if (!\is_dir(\dirname($this->storeFile))) {
            \mkdir(\dirname($this->storeFile), 0775, true);
        }

        \file_put_contents($this->storeFile, \json_encode($all, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR), \LOCK_EX);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        if (!\is_file($this->storeFile)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> */
        return \json_decode((string) \file_get_contents($this->storeFile), true, flags: \JSON_THROW_ON_ERROR);
    }
}
