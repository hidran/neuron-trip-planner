<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Booking;

use RuntimeException;

/** The offer cannot be sold any more. Retrying will not change that. */
final class SoldOut extends RuntimeException
{
}
