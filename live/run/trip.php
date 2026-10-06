<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronBook\TripPlanner\Booking\GatewayUnavailable;
use NeuronBook\TripPlanner\Requests\DecisionRequest;
use NeuronBook\TripPlanner\Requests\PaymentAuthorizationRequest;
use NeuronBook\TripPlannerLive\LiveServices;
use NeuronBook\TripPlanner\TripState;
use NeuronBook\TripPlanner\TripWorkflow;

/*
 * The same trip planner as core/run/trip.php, with real online APIs as tools.
 *
 *   php run/trip.php "Best time to visit Portugal? Two of us from Milan, 8 nights, about 3000 euros, food and old towns"
 *   php run/trip.php --resume <trip-id>
 *
 * With TRIP_TRACE=1 (the default in .env.example) every online request the
 * agents make is printed as it happens. Payments and bookings are a sandbox:
 * nothing is ever really booked, with or without Amadeus keys.
 */

$storage = \dirname(__DIR__) . '/storage';
$env = live_env();
$services = LiveServices::create($storage, provider_from_env(), $env);

echo "Live tools: forecast, holidays, exchange rates, country facts, travel advisory, guide, attractions"
    . ($env['TAVILY_API_KEY'] !== '' ? ', web search' : '') . ".\n"
    . 'Flights and hotels: ' . (LiveServices::hasAmadeus($env) ? "Amadeus ({$env['AMADEUS_ENV']} environment)" : 'sandbox (set AMADEUS_CLIENT_ID and AMADEUS_CLIENT_SECRET for real offers)') . ".\n\n";
$persistence = new FilePersistence("{$storage}/workflows");

$workflow = static fn (string $tripId, string $ask = ''): TripWorkflow => TripWorkflow::make(
    tripId: $tripId,
    services: $services,
    ask: $ask,
)->setPersistence($persistence);

$args = \array_slice($argv ?? [], 1);

if ($args === []) {
    \fwrite(STDERR, "Usage:\n  php run/trip.php \"<your trip request>\"\n  php run/trip.php --resume <trip-id>\n");
    exit(1);
}

try {
    if ($args[0] === '--resume') {
        $tripId = (string) ($args[1] ?? '');
        $snapshot = $workflow($tripId)->inspect();

        $state = match ($snapshot?->status) {
            null => exit("No trip '{$tripId}' in progress.\n"),
            // A failed run (a booking service timed out, say) recovers with a
            // plain run(): completed steps and memoized bookings are reused.
            WorkflowStatus::Failed => $workflow($tripId)->run(),
            // Still waiting for an answer: an inputless ExecutionRequest::resume() hands back the
            // paused state - or, if a deadline passed, lets the node react to it.
            default => $workflow($tripId)->run(ExecutionRequest::resume()),
        };
    } else {
        $tripId = \substr(\bin2hex(\random_bytes(4)), 0, 8);
        echo "Trip {$tripId}. Thinking...\n";
        $state = $workflow($tripId, \implode(' ', $args))->run();
    }

    while ($state->isInterrupted()) {
        $request = $state->getInterruptRequest();
        \assert($request instanceof InterruptRequest);

        $payload = ask($request);

        if ($payload === null) {
            echo "\nPaused. Pick it up later with:\n  php run/trip.php --resume {$tripId}\n";
            exit(0);
        }

        echo "Working on it...\n";
        $state = $workflow($tripId)->run(ExecutionRequest::resume($payload));
    }
} catch (GatewayUnavailable|HttpException|ProviderException $e) {
    // The run is marked failed, not lost: every completed step and every
    // memoized call - a booking included - is reused by the next run().
    echo "\n" . ($e instanceof GatewayUnavailable ? 'The booking service' : 'The model provider') . " failed: {$e->getMessage()}\n"
        . "Nothing is lost - retry with:\n  php run/trip.php --resume {$tripId}\n";
    exit(1);
} catch (RunInFlightException $e) {
    exit("That trip is already being worked on by another process.\n");
}

report($state);

/**
 * Render the pending request and turn the traveller's answer into a payload.
 * Returns null when they want to stop for now.
 *
 * @return array<string, mixed>|null
 */
function ask(InterruptRequest $request): ?array
{
    echo "\n" . \str_repeat('─', 72) . "\n";

    if ($request instanceof PaymentAuthorizationRequest) {
        echo "PAYMENT AUTHORISATION\n\n";
        foreach ($request->getLines() as $line) {
            \printf("  %-56s %9.2f\n", $line['description'], $line['amount']);
        }
        \printf("  %-56s %9.2f %s\n", 'TOTAL', $request->getAmount(), $request->getCurrency());
        echo "\nThis quote is held until {$request->getExpiresAt()?->format('H:i')}.\n";

        $answer = prompt("Type the exact total to authorise payment, 'no' to decline, Enter to pause: ");

        return match (true) {
            $answer === null || $answer === '' => null,
            \strtolower($answer) === 'no' => ['decision' => 'decline'],
            default => ['decision' => 'authorize', 'amount' => (float) \str_replace(',', '.', $answer)],
        };
    }

    if ($request instanceof DecisionRequest) {
        $d = $request->getDetails();

        if ($request->getStage() === 'window') {
            echo "PROPOSED TRIP: {$request->getMessage()}\n\n";
            echo wrap("Weather: {$d['weather']}") . "\n";
            echo wrap("Why: {$d['reasoning']}") . "\n";
        } else {
            /** @var array<string, mixed> $f */
            $f = $d['flight'];
            /** @var array<string, mixed> $h */
            $h = $d['hotel'];
            echo "PROPOSED BOOKING\n\n";
            \printf(
                "  Flight  %s, %s, %s, %sh each way   %9.2f EUR\n",
                $f['airline'],
                $f['route'],
                $f['stops'] === 0 ? 'direct' : "1 stop via {$f['via']}",
                $f['hours_each_way'],
                $f['total_eur'],
            );
            \printf("  Hotel   %s, %d*, rated %s, %s   %9.2f EUR\n", $h['name'], $h['stars'], $h['guest_rating'], $h['free_cancellation'] ? 'free cancellation' : 'non-refundable', $h['total_eur']);
            \printf("  Total   %.2f EUR%s\n\n", $d['total'], $d['over_budget'] ? '  (over your budget)' : '');
            echo wrap("Why: {$d['reasoning']}") . "\n";
        }

        $answer = prompt("\n'yes' to approve, or say what to change (Enter to pause): ");

        return match (true) {
            $answer === null || $answer === '' => null,
            \in_array(\strtolower($answer), ['y', 'yes', 'ok', 'approve'], true) => ['decision' => 'approve'],
            default => ['decision' => 'revise', 'feedback' => $answer],
        };
    }

    echo "Unexpected request: {$request->getMessage()}\n";

    return null;
}

function prompt(string $question): ?string
{
    echo $question;
    $line = \fgets(STDIN);

    if ($line === false) {
        echo "\n";

        return null;
    }

    return \trim($line);
}

function wrap(string $text): string
{
    return '  ' . \wordwrap($text, 70, "\n  ");
}

function report(TripState $state): void
{
    echo "\n" . \str_repeat('═', 72) . "\n";
    echo \strtoupper(\str_replace('_', ' ', (string) $state->outcome())) . "\n";
    echo wrap((string) $state->get('note', '')) . "\n";

    if ($state->outcome() === 'no_agreement') {
        foreach (['window' => 'Dates', 'offers' => 'Offers'] as $stage => $label) {
            foreach ($state->feedback($stage) as $i => $f) {
                echo wrap("{$label}, round " . ($i + 1) . ": {$f}") . "\n";
            }
        }
    }

    foreach ($state->bookings() as $b) {
        \printf("\n  %-8s %-14s %9.2f EUR\n  %s\n", $b['kind'], $b['reference'], $b['amount'], wrap($b['description']));
    }
}
