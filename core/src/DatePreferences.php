<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronBook\TripPlanner\Agents\DatePreferenceAgent;
use NeuronBook\TripPlanner\Output\DatePreference;

/**
 * Reads timing out of free text, for the original request and for feedback.
 *
 * Returns plain data so the caller can memoize it. A model that cannot
 * produce a valid answer yields "no timing" rather than an exception: the
 * worst case is that the advisor sees the traveller's words without a
 * code-enforced constraint - never a crashed trip.
 */
final class DatePreferences
{
    /**
     * @return array{earliest_start: string, latest_start: string, nights: int}
     */
    public static function extract(TripServices $services, string $text, string $today, string $thread): array
    {
        try {
            $preference = $services->wire(DatePreferenceAgent::make(workflowId: $thread))->structured(
                new UserMessage("Today is {$today}.\nThe traveller wrote: \"{$text}\""),
                DatePreference::class,
                maxRetries: 2,
            );
        } catch (AgentException|DeserializerException) {
            return ['earliest_start' => '', 'latest_start' => '', 'nights' => 0];
        }
        \assert($preference instanceof DatePreference);

        return [
            'earliest_start' => $preference->earliest_start,
            'latest_start' => $preference->latest_start,
            'nights' => $preference->nights,
        ];
    }
}
