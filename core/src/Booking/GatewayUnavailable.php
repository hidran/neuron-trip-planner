<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Booking;

use RuntimeException;

/** A transient failure: timeout, 5xx, rate limit. Retrying may succeed. */
final class GatewayUnavailable extends RuntimeException
{
}
