<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Intake is done: the trip request is in state.
 *
 * Events carry no data here: everything a later step needs lives in
 * TripState, which is persisted at every step. An event is only the routing
 * signal that says which node runs next.
 */
final class RequestUnderstood implements Event
{
}
