<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Tests;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronBook\TripPlanner\Booking\GatewayUnavailable;
use NeuronBook\TripPlanner\Booking\SandboxBookingGateway;
use NeuronBook\TripPlanner\Climate\FixedClimate;
use NeuronBook\TripPlanner\Geo\FixedPlaces;
use NeuronBook\TripPlanner\Geo\Place;
use NeuronBook\TripPlanner\Requests\DecisionRequest;
use NeuronBook\TripPlanner\Requests\PaymentAuthorizationRequest;
use NeuronBook\TripPlanner\Travel\SandboxInventory;
use NeuronBook\TripPlanner\TripServices;
use NeuronBook\TripPlanner\TripState;
use NeuronBook\TripPlanner\TripWorkflow;
use PHPUnit\Framework\TestCase;

/**
 * The trip planner, end to end, with a scripted model.
 *
 * Every step builds a NEW TripWorkflow from the trip ID alone and a fresh
 * TripServices, against file persistence - exactly what a second process,
 * a queue job or an HTTP request would have. What survives between steps is
 * only what the workflow persisted.
 */
final class TripPlannerTest extends TestCase
{
    private const TODAY = '2026-09-26';
    private const ASK = 'Best time to visit Mexico? Two of us from Milan, 10 nights, about 5000 euros, we love beaches and ruins.';

    private string $dir;
    private FakeAIProvider $provider;
    private SandboxBookingGateway $gateway;
    private TripServices $services;
    private FixedPlaces $places;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/trip-planner-test-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0775, true);
        $this->boot();
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function testTheWholeTripWithThreeHumanDecisions(): void
    {
        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'), ...$this->offers());

        $state = $this->start();
        $request = $this->pending($state, DecisionRequest::class);
        self::assertSame('window', $request->getStage());
        self::assertSame('Cancún, Mexico, 2027-02-10 to 2027-02-20', $request->getMessage());

        $state = $this->answer(['decision' => 'approve']);
        $request = $this->pending($state, DecisionRequest::class);
        self::assertSame('offers', $request->getStage());

        $state = $this->answer(['decision' => 'approve']);
        $payment = $this->pending($state, PaymentAuthorizationRequest::class);
        self::assertEqualsWithDelta($this->expectedTotal(), $payment->getAmount(), 0.001);
        self::assertNotNull($payment->getExpiresAt());

        $state = $this->answer(['decision' => 'authorize', 'amount' => $payment->getAmount()]);

        self::assertFalse($state->isInterrupted());
        self::assertSame('booked', $state->outcome());
        self::assertCount(2, $state->bookings());
        self::assertCount(2, $this->gateway->ledger());

        // Six model calls in total - intake, date extraction, climate tool
        // round, window, search tool round, choice - despite four separate
        // resumes that each re-executed the paused node from the top. That is
        // memoize() at work.
        $this->provider->assertCallCount(6);
    }

    public function testRevisingTheDatesFeedsTheFeedbackToTheNextProposal(): void
    {
        $this->script(
            ...$this->intake(),
            ...$this->window('Cancún', '2027-07-10'),
            ...$this->dates(), // the feedback names no dates
            ...$this->window('Kyoto', '2027-03-01'),
        );

        $this->start();
        $state = $this->answer(['decision' => 'revise', 'feedback' => 'July is hurricane season, somewhere drier please.']);

        $request = $this->pending($state, DecisionRequest::class);
        self::assertStringStartsWith('Kyoto, Japan', $request->getMessage());
        self::assertSame(['July is hurricane season, somewhere drier please.'], $state->feedback('window'));

        $this->provider->assertSent(static fn (RequestRecord $r): bool => \str_contains(
            (string) $r->messages[\count($r->messages) - 1]->getContent(),
            'July is hurricane season',
        ));
    }

    public function testAPeriodInTheRequestIsAConstraintNotASuggestion(): void
    {
        // "Second half of June": the advisor still reaches for dry February,
        // and the node sends it back until it stays inside the traveller's range.
        $this->script(
            ...$this->intake('2027-06-15', '2027-06-30'),
            ...$this->window('Cancún', '2027-02-15'),
            ...$this->window('Kyoto', '2027-06-20'),
        );

        $state = $this->start();

        self::assertSame('2027-06-20', $this->pending($state, DecisionRequest::class)->getDetails()['start']);
        self::assertStringContainsString('as the traveller asked', $state->feedback('window')[0]);
        $this->provider->assertSent(static fn (RequestRecord $r): bool => \str_contains(
            (string) $r->messages[\count($r->messages) - 1]->getContent(),
            'HARD CONSTRAINT, set by the traveller: the trip starts between 2027-06-15 and 2027-06-30',
        ));
    }

    public function testADateGivenAsFeedbackBindsTheNextProposal(): void
    {
        $this->script(
            ...$this->intake(),
            ...$this->window('Cancún', '2027-02-10'),
            ...$this->dates('2027-04-05', '2027-04-05', 7),
            ...$this->window('Cancún', '2027-03-01'),  // ignores the traveller
            ...$this->window('Cancún', '2027-04-05'),
        );

        $this->start();
        $state = $this->answer(['decision' => 'revise', 'feedback' => "Let's leave on 5 April 2027, one week."]);

        $request = $this->pending($state, DecisionRequest::class);
        self::assertSame('Cancún, Mexico, 2027-04-05 to 2027-04-12', $request->getMessage());
        self::assertSame(7, $state->request()['nights']);
    }

    public function testAPeriodThatCannotBeBookedEndsWithAClearReason(): void
    {
        $this->script(...$this->intake('2026-09-28', '2026-10-01'));

        $state = $this->start();

        self::assertSame('dates_not_bookable', $state->outcome());
        self::assertStringContainsString('2026-10-10', (string) $state->get('note'));
        $this->provider->assertCallCount(2); // intake and dates - the advisor was never asked
    }

    public function testAnOpenEndedPeriodStaysOpen(): void
    {
        $state = new TripState(['request' => ['origin' => 'MXP', 'travellers' => 2, 'nights' => 10, 'budget' => 0, 'preferences' => '']]);

        $state->applyDatePreference(['earliest_start' => '', 'latest_start' => '2027-06-20', 'nights' => 0]);

        self::assertSame(['earliest' => '', 'latest' => '2027-06-20'], $state->dates());
    }

    public function testAPlaceIdTheClimateToolNeverReturnedIsRejected(): void
    {
        // The advisor looked up Kyoto but proposes Tokyo's ID - a place it
        // never saw, so its coordinates would come from nowhere.
        $this->script(
            ...$this->intake(),
            ...$this->window('Kyoto', '2027-04-01', proposePlaceId: self::place('Tokyo')->id),
            ...$this->window('Kyoto', '2027-04-01'),
        );

        $state = $this->start();

        self::assertSame('Kyoto, Japan', $state->getInterruptRequest() instanceof DecisionRequest ? $state->getInterruptRequest()->getDetails()['name'] : null);
        self::assertStringContainsString('was not among them', $state->feedback('window')[0]);
    }

    public function testTheTravellersOwnCityIsNotADestination(): void
    {
        $this->script(...$this->intake(), ...$this->window('Milan', '2027-04-01'), ...$this->window('Rome', '2027-04-01'));

        $state = $this->start();

        self::assertStringStartsWith('Rome, Italy', $this->pending($state, DecisionRequest::class)->getMessage());
        self::assertStringContainsString('where the traveller starts from', $state->feedback('window')[0]);
    }

    public function testAnOriginNobodyCanFindEndsWithAClearReason(): void
    {
        $this->script(new AssistantMessage('{"origin_city":"Atlantis","origin_country_code":"","travellers":2,"nights":7,"budget":0,"preferences":""}'));

        $state = $this->start();

        self::assertSame('not_understood', $state->outcome());
        self::assertStringContainsString('Atlantis', (string) $state->get('note'));
    }

    public function testSeasonsAreReversedBelowTheEquator(): void
    {
        $inventory = new SandboxInventory("{$this->dir}/offers.json");
        $nightly = static fn (string $city, string $date): float => $inventory->searchHotels(self::place($city), $date, (new \DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d'), 2)[0]->nightlyRate;

        self::assertGreaterThan($nightly('Sydney', '2027-07-10'), $nightly('Sydney', '2027-01-20'), 'January is summer in Sydney.');
        self::assertGreaterThan($nightly('Kyoto', '2027-01-20'), $nightly('Kyoto', '2027-07-10'), 'July is summer in Kyoto.');
    }

    public function testALongHaulFlightConnectsThroughASensibleHub(): void
    {
        $offers = new SandboxInventory("{$this->dir}/offers.json")
            ->searchFlights(self::place('Milan'), self::place('Sydney'), '2027-03-01', '2027-03-15', 2);

        $connecting = \array_values(\array_filter($offers, static fn ($o): bool => $o->stops === 1));
        $first = \array_first($connecting);
        self::assertNotNull($first, 'A Milan-Sydney route should offer connecting flights.');
        self::assertContains($first->via, ['Dubai', 'Doha', 'Singapore', 'Hong Kong']);
    }

    public function testAWindowOutsideTheBookableRangeIsSentBackWithoutAskingTheHuman(): void
    {
        $this->script(...$this->intake(), ...$this->window('Cancún', '2026-09-30'), ...$this->window('Cancún', '2027-02-10'));

        $state = $this->start();

        self::assertSame('2027-02-10', $this->pending($state, DecisionRequest::class)->getDetails()['start']);
        self::assertStringStartsWith('(automatic check)', $state->feedback('window')[0]);
    }

    public function testAModelThatCannotFillTheStructureGetsAnotherRoundInsteadOfCrashing(): void
    {
        // maxRetries: 2 means three attempts, all of them blank - what a small
        // local model sometimes does - then a good proposal in the next round.
        $blank = new AssistantMessage('{"destination":"cancun","start_date":"2027-02-10","weather_summary":"","reasoning":""}');
        $this->script(...$this->intake(), ...[$blank, $blank, $blank], ...$this->window('Cancún', '2027-02-10'));

        $state = $this->start();

        self::assertSame('window', $this->pending($state, DecisionRequest::class)->getStage());
        self::assertStringContainsString('cannot be blank', $state->feedback('window')[0]);
    }

    public function testAnOfferIdTheSearchNeverReturnedIsRejected(): void
    {
        [$flightId, $hotelId] = $this->offerIds();
        $this->script(
            ...$this->intake(),
            ...$this->window('Cancún', '2027-02-10'),
            ...$this->choice('FL-INVENTED', $hotelId),
            ...$this->choice($flightId, $hotelId),
        );

        $this->start();
        $state = $this->answer(['decision' => 'approve']);

        self::assertSame('offers', $this->pending($state, DecisionRequest::class)->getStage());
        self::assertStringContainsString('FL-INVENTED', $state->feedback('offers')[0]);
    }

    public function testTheAuthorisationMustRepeatTheExactAmount(): void
    {
        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'), ...$this->offers());
        $this->approveUntilPayment();

        $state = $this->answer(['decision' => 'authorize', 'amount' => 1.00]);

        $payment = $this->pending($state, PaymentAuthorizationRequest::class);
        self::assertSame([], $this->gateway->ledger(), 'Nothing may be booked on a mismatched amount.');

        $state = $this->answer(['decision' => 'authorize', 'amount' => $payment->getAmount()]);
        self::assertSame('booked', $state->outcome());
    }

    public function testAnAuthorisationNobodyAnswersExpiresWithoutBooking(): void
    {
        $this->boot(authorizationWindow: '+1 second');
        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'), ...$this->offers());
        $this->approveUntilPayment();

        \sleep(2);
        $state = $this->workflow()->resume()->run();

        self::assertSame('authorization_expired', $state->outcome());
        self::assertSame([], $this->gateway->ledger());
    }

    public function testASoldOutHotelCancelsTheFlight(): void
    {
        $this->boot(hotelSoldOut: true);
        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'), ...$this->offers());
        $payment = $this->approveUntilPayment();

        $state = $this->answer(['decision' => 'authorize', 'amount' => $payment->getAmount()]);

        self::assertSame('hotel_sold_out', $state->outcome());
        $kinds = \array_column($this->gateway->ledger(), 'kind');
        self::assertSame(['flight', 'cancellation'], $kinds);
    }

    public function testACrashAfterTheFlightIsRecoveredWithoutBookingItTwice(): void
    {
        $this->boot(failNextHotelCalls: 1);
        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'), ...$this->offers());
        $payment = $this->approveUntilPayment();

        try {
            $this->answer(['decision' => 'authorize', 'amount' => $payment->getAmount()]);
            self::fail('The transient failure should have failed the run.');
        } catch (GatewayUnavailable) {
        }

        $flightRef = $this->gateway->ledger()['trip:t1:flight']['reference'];

        // Later - a retry job, a cron, a human pressing "try again".
        $state = $this->workflow()->run();

        self::assertSame('booked', $state->outcome());
        self::assertSame($flightRef, $this->gateway->ledger()['trip:t1:flight']['reference']);
        self::assertCount(2, $this->gateway->ledger(), 'One flight, one hotel - never two flights.');
    }

    public function testAFinishedTripCannotBeAnsweredAgain(): void
    {
        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'), ...$this->offers());
        $payment = $this->approveUntilPayment();
        $this->answer(['decision' => 'authorize', 'amount' => $payment->getAmount()]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No run in flight');
        $this->answer(['decision' => 'authorize', 'amount' => $payment->getAmount()]);
    }

    // --- helpers -----------------------------------------------------------

    private function boot(string $authorizationWindow = '+15 minutes', bool $hotelSoldOut = false, int $failNextHotelCalls = 0): void
    {
        $this->authorizationWindow = $authorizationWindow;
        $this->provider ??= new FakeAIProvider();
        // One gazetteer per test: like the real directory's JSON store, it
        // remembers what earlier "processes" searched.
        $this->places ??= new FixedPlaces();
        $this->gateway = new SandboxBookingGateway("{$this->dir}/bookings.json", $hotelSoldOut, $failNextHotelCalls);
        $this->services = new TripServices(
            provider: $this->provider,
            places: $this->places,
            climate: new FixedClimate(),
            inventory: new SandboxInventory("{$this->dir}/offers.json"),
            bookings: $this->gateway,
        );
    }

    private string $authorizationWindow = '+15 minutes';

    private function workflow(): TripWorkflow
    {
        return TripWorkflow::make(
            tripId: 't1',
            services: $this->services,
            ask: self::ASK,
            today: self::TODAY,
            authorizationWindow: $this->authorizationWindow,
        )->setPersistence(new FilePersistence("{$this->dir}/workflows"));
    }

    private function start(): TripState
    {
        return $this->workflow()->run();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function answer(array $payload): TripState
    {
        return $this->workflow()->resume($payload)->run();
    }

    private function approveUntilPayment(): PaymentAuthorizationRequest
    {
        $this->start();
        $this->answer(['decision' => 'approve']);

        return $this->pending($this->answer(['decision' => 'approve']), PaymentAuthorizationRequest::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function pending(TripState $state, string $class): object
    {
        self::assertTrue($state->isInterrupted(), 'Expected the trip to be waiting for the traveller. Outcome: ' . \var_export($state->outcome(), true));
        $request = $state->getInterruptRequest();
        self::assertInstanceOf($class, $request);

        return $request;
    }

    private function script(Message ...$responses): void
    {
        $this->provider->addResponses(...$responses);
    }

    /** @return list<Message> */
    private function intake(string $earliest = '', string $latest = '', int $nights = 0): array
    {
        return [
            new AssistantMessage('{"origin_city":"Milan","origin_country_code":"IT","travellers":2,"nights":10,"budget":5000,"preferences":"beaches and ruins"}'),
            ...$this->dates($earliest, $latest, $nights),
        ];
    }

    /**
     * The date-preference extraction: once for the request, and once for
     * every piece of human feedback on the dates.
     *
     * @return list<Message>
     */
    private function dates(string $earliest = '', string $latest = '', int $nights = 0): array
    {
        return [new AssistantMessage(\json_encode(
            ['earliest_start' => $earliest, 'latest_start' => $latest, 'nights' => $nights],
            \JSON_THROW_ON_ERROR,
        ))];
    }

    /**
     * The advisor's turn: look a city up with the climate tool, then propose
     * it by the place_id the tool returned.
     *
     * @return list<Message>
     */
    private function window(string $city, string $start, ?int $proposePlaceId = null): array
    {
        $place = self::place($city);

        return [
            new ToolCallMessage(null, [ToolCall::make('get_monthly_climate', 'call_' . \bin2hex(\random_bytes(3)), ['city' => $place->name, 'country_code' => $place->countryCode])]),
            new AssistantMessage(\json_encode([
                'destination_place_id' => $proposePlaceId ?? $place->id,
                'start_date' => $start,
                'weather_summary' => 'Dry and warm, around 28 C, little rain.',
                'reasoning' => 'The dry season fits beaches and ruins.',
            ], \JSON_THROW_ON_ERROR)),
        ];
    }

    private static function place(string $name): Place
    {
        return \array_first(\array_filter(FixedPlaces::defaults(), static fn (Place $p): bool => $p->name === $name))
            ?? throw new \LogicException("No fixture place {$name}");
    }

    /** @return list<Message> */
    private function offers(): array
    {
        [$flightId, $hotelId] = $this->offerIds();

        return $this->choice($flightId, $hotelId);
    }

    /** @return list<Message> */
    private function choice(string $flightId, string $hotelId): array
    {
        return [
            new ToolCallMessage(null, [
                ToolCall::make('search_flights', 'call_' . \bin2hex(\random_bytes(3)), []),
                ToolCall::make('search_hotels', 'call_' . \bin2hex(\random_bytes(3)), ['min_stars' => 4]),
            ]),
            new AssistantMessage(\json_encode([
                'flight_offer_id' => $flightId,
                'hotel_offer_id' => $hotelId,
                'reasoning' => 'Cheapest one-stop flight and a well-rated 4-star with free cancellation.',
            ], \JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * The sandbox is deterministic, so the test can know the IDs the scout
     * will be shown - which is what lets the scripted model "choose" them.
     *
     * @return array{string, string}
     */
    private function offerIds(): array
    {
        $inventory = new SandboxInventory("{$this->dir}/offers.json");
        $flight = $inventory->searchFlights(self::place('Milan'), self::place('Cancún'), '2027-02-10', '2027-02-20', 2)[1];
        $hotel = $inventory->searchHotels(self::place('Cancún'), '2027-02-10', '2027-02-20', 2)[0];

        return [$flight->id, $hotel->id];
    }

    private function expectedTotal(): float
    {
        $inventory = new SandboxInventory("{$this->dir}/offers.json");
        $flight = $inventory->searchFlights(self::place('Milan'), self::place('Cancún'), '2027-02-10', '2027-02-20', 2)[1];
        $hotel = $inventory->searchHotels(self::place('Cancún'), '2027-02-10', '2027-02-20', 2)[0];

        return \round($flight->total() + $hotel->total(), 2);
    }
}
