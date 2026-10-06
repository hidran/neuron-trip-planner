<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

/**
 * The online services, built once over one Http.
 */
final class LiveApis
{
    public function __construct(
        public readonly RestCountries $countries,
        public readonly Frankfurter $rates,
        public readonly NagerDate $holidays,
        public readonly WikiSummary $guide,
        public readonly WikiSummary $encyclopedia,
        public readonly TravelAdvisory $advisories,
        public readonly OpenMeteoForecast $forecast,
        public readonly Overpass $attractions,
    ) {
    }

    public static function over(Http $http): self
    {
        return new self(
            countries: new RestCountries($http),
            rates: new Frankfurter($http),
            holidays: new NagerDate($http),
            guide: new WikiSummary($http, 'en.wikivoyage.org'),
            encyclopedia: new WikiSummary($http, 'en.wikipedia.org'),
            advisories: new TravelAdvisory($http),
            forecast: new OpenMeteoForecast($http),
            attractions: new Overpass($http),
        );
    }
}
