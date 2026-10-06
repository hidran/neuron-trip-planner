<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;
use NeuronBook\TripPlannerLive\Api\NagerDate;

/**
 * Which days of a trip are public holidays at the destination.
 *
 * Holidays cut both ways for a traveller: closed museums and full trains on
 * one hand, a festival worth planning around on the other. Either way it is
 * worth knowing before the dates are agreed, not after.
 */
class GetPublicHolidaysTool extends LiveTool
{
    protected string $name = 'get_public_holidays';

    protected ?string $description = 'Lists the public holidays in a country between two dates, from a live holiday '
        . 'calendar. Use it to warn about closures, crowds and price peaks on the proposed dates, or to point out a '
        . 'festival. An empty list means there are none in that period; the tool reports separately when a country is '
        . 'not covered.';

    public function __construct(private readonly NagerDate $holidays)
    {
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'country_code', type: PropertyType::STRING, description: 'ISO 3166-1 alpha-2 code. Example: "PT".', required: true),
            new ToolProperty(name: 'start_date', type: PropertyType::STRING, description: 'First day of the period, YYYY-MM-DD.', required: true),
            new ToolProperty(name: 'end_date', type: PropertyType::STRING, description: 'Last day of the period, YYYY-MM-DD.', required: true),
        ];
    }

    public function __invoke(string $country_code, string $start_date, string $end_date): string|ToolOutput
    {
        if (!self::isDate($start_date) || !self::isDate($end_date) || $end_date < $start_date) {
            return ToolOutput::error('Give two valid dates as YYYY-MM-DD, with end_date not before start_date.');
        }

        try {
            $holidays = $this->holidays->between($country_code, $start_date, $end_date);
        } catch (ApiUnavailable $e) {
            return self::unavailable('public-holiday', $e);
        }

        return $holidays === null
            ? ToolOutput::error("The holiday calendar does not cover \"{$country_code}\". Say that you could not check holidays; do not list any from memory.")
            : self::json(['period' => "{$start_date} to {$end_date}", 'holidays' => $holidays]);
    }
}
