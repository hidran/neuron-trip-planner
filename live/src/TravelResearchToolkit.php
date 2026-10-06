<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive;

use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronBook\TripPlanner\Geo\PlaceDirectory;
use NeuronBook\TripPlannerLive\Api\LiveApis;
use NeuronBook\TripPlannerLive\Tools\ConvertCurrencyTool;
use NeuronBook\TripPlannerLive\Tools\GetAttractionsTool;
use NeuronBook\TripPlannerLive\Tools\GetCountryInfoTool;
use NeuronBook\TripPlannerLive\Tools\GetDestinationGuideTool;
use NeuronBook\TripPlannerLive\Tools\GetPublicHolidaysTool;
use NeuronBook\TripPlannerLive\Tools\GetTravelAdvisoryTool;
use NeuronBook\TripPlannerLive\Tools\GetWeatherForecastTool;

/**
 * The seven live-API tools, as one toolkit.
 *
 * A toolkit does two jobs a bare list of tools cannot. It travels as a unit,
 * so wiring an agent is one line. And its guidelines() text is added to the
 * agent's instructions automatically whenever the toolkit is attached: the
 * rules for using these tools live next to the tools, not copied into every
 * agent's prompt.
 *
 * only() narrows it per agent: the scout needs rates and holidays, the
 * advisor needs everything.
 *
 * @method static static make(PlaceDirectory $places, LiveApis $apis)
 */
class TravelResearchToolkit extends AbstractToolkit
{
    public function __construct(
        private readonly PlaceDirectory $places,
        private readonly LiveApis $apis,
    ) {
    }

    public function guidelines(): ?string
    {
        return <<<'TXT'
            The research tools below call live online services. Treat what they return as the only source of facts about the world.
            - Every number, date, holiday, rate or advisory you state must come from a tool result in this conversation. If you did not call the tool, do not state the fact.
            - Cite where a fact came from in a few words ("per the travel advisory, updated 2026-09-30"), and give the date of anything that changes, like rates and advisories.
            - A tool that reports an error or says it has no data is not a reason to guess. Say in one sentence that you could not check it, and carry on with what you do know.
            - Call only the tools that change the decision. Two or three well-chosen calls beat seven.
            - Tools take a place_id that get_monthly_climate returned; never type coordinates or invent an id.
            TXT;
    }

    public function provide(): array
    {
        return [
            new GetCountryInfoTool($this->apis->countries),
            new ConvertCurrencyTool($this->apis->rates),
            new GetPublicHolidaysTool($this->apis->holidays),
            new GetTravelAdvisoryTool($this->apis->advisories),
            new GetWeatherForecastTool($this->places, $this->apis->forecast),
            new GetAttractionsTool($this->places, $this->apis->attractions),
            new GetDestinationGuideTool($this->places, $this->apis->guide, $this->apis->encyclopedia),
        ];
    }
}
