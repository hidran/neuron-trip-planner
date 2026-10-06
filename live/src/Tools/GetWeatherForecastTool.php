<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tools;

use DateTimeImmutable;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronBook\TripPlanner\Geo\PlaceDirectory;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;
use NeuronBook\TripPlannerLive\Api\OpenMeteoForecast;

/**
 * The actual forecast, for trips that start soon.
 *
 * Climate says what a month is usually like; a forecast says what the next
 * days will be. Beyond the forecast horizon there is nothing to report, and
 * the tool refuses instead of padding the answer with climate data dressed up
 * as a forecast.
 */
class GetWeatherForecastTool extends LiveTool
{
    protected string $name = 'get_weather_forecast';

    protected ?string $description = 'Returns the real day-by-day weather forecast (high and low in C, rain in mm, rain '
        . 'probability) for a place, from a live forecast service. It only reaches 16 days ahead: for later dates it '
        . 'answers that no forecast exists, and you should rely on get_monthly_climate instead and say so.';

    public function __construct(
        private readonly PlaceDirectory $places,
        private readonly OpenMeteoForecast $forecast,
        private readonly ?DateTimeImmutable $today = null,
    ) {
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'place_id', type: PropertyType::INTEGER, description: 'The place_id returned by get_monthly_climate.', required: true),
            new ToolProperty(name: 'start_date', type: PropertyType::STRING, description: 'First day, YYYY-MM-DD.', required: true),
            new ToolProperty(name: 'end_date', type: PropertyType::STRING, description: 'Last day, YYYY-MM-DD.', required: true),
        ];
    }

    public function __invoke(int $place_id, string $start_date, string $end_date): string|ToolOutput
    {
        $place = self::place($this->places, $place_id);

        if ($place instanceof ToolOutput) {
            return $place;
        }

        if (!self::isDate($start_date) || !self::isDate($end_date) || $end_date < $start_date) {
            return ToolOutput::error('Give two valid dates as YYYY-MM-DD, with end_date not before start_date.');
        }

        $today = $this->today ?? new DateTimeImmutable('today');
        $limit = $today->modify('+' . OpenMeteoForecast::HORIZON_DAYS . ' days')->format('Y-m-d');

        if ($end_date > $limit || $start_date < $today->format('Y-m-d')) {
            return ToolOutput::error(
                "No forecast exists for {$start_date} to {$end_date}: forecasts cover today to {$limit}. "
                . 'Use get_monthly_climate for typical weather and say that this is not a forecast.',
            );
        }

        try {
            $days = $this->forecast->daily($place->latitude, $place->longitude, $start_date, $end_date);
        } catch (ApiUnavailable $e) {
            return self::unavailable('weather-forecast', $e);
        }

        return self::json(['place' => $place->label(), 'days' => $days]);
    }
}
