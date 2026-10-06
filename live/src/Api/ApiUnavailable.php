<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

use RuntimeException;
use Throwable;

/**
 * An online API did not give a usable answer: unreachable, an error status, or
 * a body that is not JSON.
 *
 * The status is kept because the difference matters to a caller: a 404 from a
 * country service means "no such country", a 503 means "try again later".
 * Tools turn this into a message the model can act on; nothing here is ever
 * allowed to leak a stack trace into a prompt.
 */
final class ApiUnavailable extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
