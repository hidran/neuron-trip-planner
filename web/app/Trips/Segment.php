<?php

declare(strict_types=1);

namespace App\Trips;

/** What a queued trip job should do with the workflow. */
enum Segment: string
{
    case Start = 'start';
    case Answer = 'answer';
    case Retry = 'retry';
}
