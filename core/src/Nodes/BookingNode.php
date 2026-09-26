<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Nodes;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronBook\TripPlanner\Booking\Booking;
use NeuronBook\TripPlanner\Booking\SoldOut;
use NeuronBook\TripPlanner\Events\PaymentAuthorized;
use NeuronBook\TripPlanner\TripServices;
use NeuronBook\TripPlanner\TripState;

/**
 * Step 5 - spend the money, exactly once, or not at all.
 *
 * Two bookings that must succeed together, with no transaction spanning an
 * airline and a hotel chain. So:
 *
 * - each booking is memoized AND carries an idempotency key. The memo means
 *   a recovered run does not call the gateway again; the key covers the one
 *   gap a memo cannot - a crash after the gateway said yes and before the
 *   memo was written;
 * - a SoldOut hotel is a business outcome: cancel the flight (compensation)
 *   and finish cleanly. A transient GatewayUnavailable is left to propagate:
 *   the run is marked failed, and a plain run() later recovers it - reusing
 *   the flight memo, so the flight is never booked twice.
 */
class BookingNode extends Node
{
    public function __construct(private readonly TripServices $services)
    {
    }

    public function __invoke(PaymentAuthorized $event, TripState $state): StopEvent
    {
        $key = (string) $state->getWorkflowId();
        $selection = $state->selection();
        $inventory = $this->services->inventory;

        $flightOffer = $inventory->quoteFlight($selection['flight_id']);
        $hotelOffer = $inventory->quoteHotel($selection['hotel_id']);

        if ($flightOffer === null || $hotelOffer === null) {
            $state->finish('offer_unavailable', 'An offer disappeared after authorisation. Nothing was booked.');

            return new StopEvent();
        }

        // Never charge more than the traveller authorised.
        if ($flightOffer->total() + $hotelOffer->total() > $state->authorizedAmount() + 0.005) {
            $state->finish('price_changed', 'The price rose after authorisation. Nothing was booked.');

            return new StopEvent();
        }

        $flight = Booking::fromArray($this->memoize(
            'book-flight',
            fn (): array => $this->services->bookings->bookFlight($flightOffer, "{$key}:flight")->toArray(),
        ));

        try {
            $hotel = Booking::fromArray($this->memoize(
                'book-hotel',
                fn (): array => $this->services->bookings->bookHotel($hotelOffer, "{$key}:hotel")->toArray(),
            ));
        } catch (SoldOut $e) {
            $this->memoize('cancel-flight', function () use ($flight, $key): bool {
                $this->services->bookings->cancel($flight, "{$key}:cancel-flight");

                return true;
            });

            $state->recordBooking($flight);
            $state->finish('hotel_sold_out', "{$e->getMessage()} The flight {$flight->reference} was cancelled and refunded.");

            return new StopEvent();
        }

        $state->recordBooking($flight);
        $state->recordBooking($hotel);
        $state->finish('booked', "Flight {$flight->reference} and hotel {$hotel->reference} are confirmed.");

        return new StopEvent();
    }
}
