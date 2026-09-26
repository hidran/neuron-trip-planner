<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Booking;

use NeuronBook\TripPlanner\Travel\FlightOffer;
use NeuronBook\TripPlanner\Travel\HotelOffer;

/**
 * The side of the system that spends money.
 *
 * Every call carries an idempotency key. The workflow memoizes each booking,
 * but a memo only helps once the step has committed: a worker that dies after
 * the airline confirmed and before the commit will call again on recovery.
 * The key is what makes that second call return the first booking.
 *
 * @throws SoldOut when the offer can no longer be sold - a business outcome,
 *         which the workflow answers by compensating.
 * @throws GatewayUnavailable for anything transient - the workflow lets it
 *         fail, and a plain run() recovers it later.
 */
interface BookingGateway
{
    public function bookFlight(FlightOffer $offer, string $idempotencyKey): Booking;

    public function bookHotel(HotelOffer $offer, string $idempotencyKey): Booking;

    public function cancel(Booking $booking, string $idempotencyKey): void;
}
