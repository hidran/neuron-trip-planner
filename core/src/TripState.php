<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner;

use NeuronAI\Workflow\WorkflowState;
use NeuronBook\TripPlanner\Booking\Booking;
use NeuronBook\TripPlanner\Geo\Place;

/**
 * Everything the trip knows, as plain arrays behind named accessors.
 *
 * The state is serialised at every step commit, so it holds data only: IDs,
 * dates, amounts, the traveller's feedback. Never an offer object that talks
 * to an API, never a connection. Nodes rehydrate what they need from IDs.
 */
class TripState extends WorkflowState
{
    public function ask(): string
    {
        return (string) $this->get('ask', '');
    }

    public function today(): string
    {
        return (string) $this->get('today', \date('Y-m-d'));
    }

    /**
     * @return array{origin_city: string, origin_country_code: string, travellers: int, nights: int, budget: int, preferences: string}
     */
    public function request(): array
    {
        /** @var array{origin_city: string, origin_country_code: string, travellers: int, nights: int, budget: int, preferences: string} */
        return $this->get('request');
    }

    /** The geocoded departure city. */
    public function origin(): Place
    {
        /** @var array<string, mixed> $origin */
        $origin = $this->get('origin');

        return Place::fromArray($origin);
    }

    /**
     * @return list<string>
     */
    public function feedback(string $stage): array
    {
        /** @var list<string> */
        return $this->get("feedback.{$stage}", []);
    }

    public function addFeedback(string $stage, string $feedback): void
    {
        $this->set("feedback.{$stage}", [...$this->feedback($stage), $feedback]);
    }

    /**
     * The start-date range the traveller asked for. Empty strings mean open.
     *
     * @return array{earliest: string, latest: string}
     */
    public function dates(): array
    {
        /** @var array{earliest: string, latest: string} */
        return $this->get('dates', ['earliest' => '', 'latest' => '']);
    }

    /**
     * Record timing the traveller stated. Later statements replace earlier
     * ones: "actually, let's go in May" is how people change their minds.
     *
     * @param array{earliest_start: string, latest_start: string, nights: int} $preference
     */
    public function applyDatePreference(array $preference): void
    {
        $earliest = $preference['earliest_start'];
        $latest = $preference['latest_start'];

        if ($earliest !== '' || $latest !== '') {
            // An empty side stays open: "before 20 June" has no earliest date.
            if ($earliest !== '' && $latest !== '' && $latest < $earliest) {
                [$earliest, $latest] = [$latest, $earliest];
            }
            $this->set('dates', ['earliest' => $earliest, 'latest' => $latest]);
        }

        if ($preference['nights'] > 0) {
            $request = $this->request();
            $request['nights'] = $preference['nights'];
            $this->set('request', $request);
        }
    }

    /**
     * @return array{place: array<string, mixed>, name: string, start: string, end: string, weather: string, reasoning: string}
     */
    public function window(): array
    {
        /** @var array{place: array<string, mixed>, name: string, start: string, end: string, weather: string, reasoning: string} */
        return $this->get('window');
    }

    /** The agreed destination. */
    public function destination(): Place
    {
        return Place::fromArray($this->window()['place']);
    }

    /**
     * @return array{flight_id: string, hotel_id: string, reasoning: string}
     */
    public function selection(): array
    {
        /** @var array{flight_id: string, hotel_id: string, reasoning: string} */
        return $this->get('selection');
    }

    public function authorizedAmount(): float
    {
        return (float) $this->get('authorized_amount', 0.0);
    }

    public function recordBooking(Booking $booking): void
    {
        $this->set('bookings', [...$this->bookings(), $booking->toArray()]);
    }

    /**
     * @return list<array{reference: string, kind: string, offerId: string, amount: float, description: string}>
     */
    public function bookings(): array
    {
        /** @var list<array{reference: string, kind: string, offerId: string, amount: float, description: string}> */
        return $this->get('bookings', []);
    }

    public function finish(string $outcome, string $note = ''): void
    {
        $this->set('outcome', $outcome);
        $this->set('note', $note);
    }

    public function outcome(): ?string
    {
        $outcome = $this->get('outcome');

        return \is_string($outcome) ? $outcome : null;
    }
}
