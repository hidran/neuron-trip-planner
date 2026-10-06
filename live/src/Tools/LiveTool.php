<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronBook\TripPlanner\Geo\Place;
use NeuronBook\TripPlanner\Geo\PlaceDirectory;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;

/**
 * What every tool that calls an online API shares.
 *
 * Three rules, learned the hard way by every agent that ever called a web
 * service:
 *
 * 1. The model never supplies coordinates. It names a place_id that an earlier
 *    search really returned; the coordinates come from the directory. A
 *    hallucinated latitude is not possible.
 * 2. A failing service is an ordinary result, not an exception. The tool
 *    returns an error the model can read ("could not check, say so") and the
 *    conversation goes on. A stack trace in a prompt helps nobody.
 * 3. The output is small. Tools return the few fields a decision needs, not
 *    the service's whole payload, because every token returned is a token
 *    paid for, twice, on every following turn.
 */
abstract class LiveTool extends Tool
{
    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    protected static function json(array $data): string
    {
        return \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    protected static function unavailable(string $what, ApiUnavailable $e): ToolOutput
    {
        return ToolOutput::error(
            "The {$what} service did not answer ({$e->getMessage()}). Try once more at most. "
            . "If it still fails, tell the traveller you could not check this, and do not guess.",
        );
    }

    protected static function place(PlaceDirectory $places, int $placeId): Place|ToolOutput
    {
        return $places->find($placeId)
            ?? ToolOutput::error("Unknown place_id {$placeId}. Use a place_id returned by get_monthly_climate, never one you made up.");
    }

    protected static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
