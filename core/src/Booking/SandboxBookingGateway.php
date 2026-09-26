<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Booking;

use NeuronBook\TripPlanner\Travel\FlightOffer;
use NeuronBook\TripPlanner\Travel\HotelOffer;
use RuntimeException;

/**
 * A booking system that books nothing, with the properties of one that does.
 *
 * The ledger is a JSON file keyed by idempotency key: the same key always
 * returns the same booking, whichever process asks. Two switches let the
 * tests and the demo exercise the failure paths:
 *
 * - $hotelSoldOut: every hotel booking throws SoldOut (compensation path);
 * - $failNextHotelCalls: the next N hotel calls throw GatewayUnavailable,
 *   after the flight is already booked (crash-recovery path).
 */
final class SandboxBookingGateway implements BookingGateway
{
    public function __construct(
        private readonly string $ledgerFile,
        private readonly bool $hotelSoldOut = false,
        private int $failNextHotelCalls = 0,
    ) {
    }

    public function bookFlight(FlightOffer $offer, string $idempotencyKey): Booking
    {
        return $this->once($idempotencyKey, function () use ($offer): Booking {
            return new Booking(
                reference: 'PNR' . \strtoupper(\substr(\hash('crc32b', $offer->id . \microtime()), 0, 5)),
                kind: 'flight',
                offerId: $offer->id,
                amount: $offer->total(),
                description: "{$offer->airline}, {$offer->origin} - {$offer->destination}" . ($offer->via !== '' ? " via {$offer->via}" : '') . ", {$offer->departDate} / {$offer->returnDate}, {$offer->travellers} pax",
            );
        });
    }

    public function bookHotel(HotelOffer $offer, string $idempotencyKey): Booking
    {
        return $this->once($idempotencyKey, function () use ($offer): Booking {
            if ($this->failNextHotelCalls > 0) {
                $this->failNextHotelCalls--;

                throw new GatewayUnavailable('Hotel booking service timed out.');
            }

            if ($this->hotelSoldOut) {
                throw new SoldOut("{$offer->name} has no rooms left for {$offer->checkIn}.");
            }

            return new Booking(
                reference: 'HTL-' . \strtoupper(\substr(\hash('crc32b', $offer->id . \microtime()), 0, 6)),
                kind: 'hotel',
                offerId: $offer->id,
                amount: $offer->total(),
                description: "{$offer->name}, {$offer->checkIn} to {$offer->checkOut}, {$offer->nights} nights",
            );
        });
    }

    public function cancel(Booking $booking, string $idempotencyKey): void
    {
        $this->once($idempotencyKey, fn (): Booking => new Booking(
            reference: 'CXL-' . $booking->reference,
            kind: 'cancellation',
            offerId: $booking->offerId,
            amount: -$booking->amount,
            description: "Cancelled {$booking->reference}, refund {$booking->amount} EUR",
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function ledger(): array
    {
        if (!\is_file($this->ledgerFile)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $rows */
        $rows = \json_decode((string) \file_get_contents($this->ledgerFile), true, flags: \JSON_THROW_ON_ERROR);

        return $rows;
    }

    /**
     * @param callable(): Booking $action
     */
    private function once(string $key, callable $action): Booking
    {
        $ledger = $this->ledger();

        if (isset($ledger[$key])) {
            return Booking::fromArray($ledger[$key]);
        }

        $booking = $action();
        $ledger[$key] = $booking->toArray();

        $dir = \dirname($this->ledgerFile);
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        \file_put_contents($this->ledgerFile, \json_encode($ledger, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR), \LOCK_EX);

        return $booking;
    }
}
