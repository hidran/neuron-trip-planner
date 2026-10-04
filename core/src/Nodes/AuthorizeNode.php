<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Nodes;

use Closure;
use DateTimeImmutable;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronBook\TripPlanner\Events\OffersChosen;
use NeuronBook\TripPlanner\Events\PaymentAuthorized;
use NeuronBook\TripPlanner\Requests\PaymentAuthorizationRequest;
use NeuronBook\TripPlanner\TripServices;
use NeuronBook\TripPlanner\TripState;

/**
 * Step 4 - may I spend this? Human checkpoint #3.
 *
 * No model here. The amount is re-quoted from the inventory - fares move
 * between "that looks good" and "pay" - and memoized, together with the
 * deadline, so a resume shows and checks the same figure the traveller saw.
 *
 * The answer must repeat the amount. A mismatch is not an error to throw:
 * it loops back for a fresh quote, bounded like every loop in this workflow.
 */
class AuthorizeNode extends Node
{
    public const MAX_ROUNDS = 3;

    public function __construct(
        private readonly TripServices $services,
        private readonly string $window,
        // PHP 8.5: a closure as a default value. Production gets the real
        // clock; a test can pass a fixed one without a clock interface.
        private readonly Closure $now = static function (): DateTimeImmutable {
            return new DateTimeImmutable();
        },
    ) {
    }

    public function __invoke(OffersChosen $event, TripState $state): OffersChosen|PaymentAuthorized|StopEvent
    {
        $round = (int) $state->get('authorization_round', 0);

        /** @var array{amount: float, lines: list<array{description: string, amount: float}>, deadline: int}|array{unavailable: true} $quote */
        $quote = $this->memoize("quote-{$round}", fn (): array => $this->quote($state));

        if (isset($quote['unavailable'])) {
            $state->finish('offer_unavailable', 'The chosen flight or hotel is no longer available.');

            return new StopEvent();
        }

        $payload = $this->interrupt(new PaymentAuthorizationRequest(
            amount: $quote['amount'],
            currency: 'EUR',
            lines: $quote['lines'],
            expiresAt: (new DateTimeImmutable())->setTimestamp($quote['deadline']),
        ));

        // null: the deadline passed and an inputless run(ExecutionRequest::resume()) arrived.
        if ($payload === null) {
            $state->finish('authorization_expired', 'The payment authorisation window closed. Nothing was booked.');

            return new StopEvent();
        }

        if (($payload['decision'] ?? null) !== 'authorize') {
            $state->finish('declined', 'The traveller declined the payment. Nothing was booked.');

            return new StopEvent();
        }

        if (\abs((float) ($payload['amount'] ?? 0) - $quote['amount']) >= 0.005) {
            $state->set('authorization_round', $round + 1);

            if ($round + 1 >= self::MAX_ROUNDS) {
                $state->finish('authorization_failed', 'The authorised amount never matched the quote.');

                return new StopEvent();
            }

            return new OffersChosen();
        }

        $state->set('authorized_amount', $quote['amount']);

        return new PaymentAuthorized();
    }

    /**
     * @return array{amount: float, lines: list<array{description: string, amount: float}>, deadline: int}|array{unavailable: true}
     */
    private function quote(TripState $state): array
    {
        $selection = $state->selection();
        $flight = $this->services->inventory->quoteFlight($selection['flight_id']);
        $hotel = $this->services->inventory->quoteHotel($selection['hotel_id']);

        if ($flight === null || $hotel === null) {
            return ['unavailable' => true];
        }

        return [
            'amount' => \round($flight->total() + $hotel->total(), 2),
            'lines' => [
                ['description' => "Flight: {$flight->airline}, {$flight->departDate} / {$flight->returnDate}, {$flight->travellers} pax", 'amount' => $flight->total()],
                ['description' => "Hotel: {$hotel->name}, {$hotel->nights} nights from {$hotel->checkIn}", 'amount' => $hotel->total()],
            ],
            'deadline' => ($this->now)()->modify($this->window)->getTimestamp(),
        ];
    }
}
