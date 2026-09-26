<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronBook\TripPlanner\Booking\SandboxBookingGateway;
use NeuronBook\TripPlanner\Climate\FixedClimate;
use NeuronBook\TripPlanner\Geo\FixedPlaces;
use NeuronBook\TripPlanner\Geo\Place;
use NeuronBook\TripPlanner\Travel\SandboxInventory;
use NeuronBook\TripPlanner\TripServices;
use Tests\TestCase;

/**
 * The trip planner through its HTTP API, with a scripted model.
 *
 * The queue runs synchronously here, so every 202 has finished its work by
 * the time the next request is made - the same sequence the SPA drives by
 * polling. The workflow's durable state goes through EloquentPersistence into
 * the workflow_store table, exactly as in production.
 */
final class TripApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeAIProvider $provider;
    private SandboxBookingGateway $gateway;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-26 10:00:00');
        $this->dir = storage_path('framework/testing/trips-' . \bin2hex(\random_bytes(4)));
        $this->provider = new FakeAIProvider();
        $this->bind();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_trip_goes_from_request_to_booking_with_three_answers(): void
    {
        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'), ...$this->offers());

        $id = $this->postJson('/api/trips', ['ask' => 'Best time for Mexico? Two of us from Milan, 10 nights.'])
            ->assertAccepted()
            ->json('data.id');

        $this->getJson("/api/trips/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.pending.type', 'decision')
            ->assertJsonPath('data.pending.stage', 'window')
            ->assertJsonPath('data.pending.details.name', 'Cancún, Mexico')
            ->assertJsonPath('data.pending.details.start', '2027-02-10');

        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'approve'])->assertAccepted();

        $this->getJson("/api/trips/{$id}")
            ->assertJsonPath('data.pending.stage', 'offers')
            ->assertJsonPath('data.summary.window.name', 'Cancún, Mexico');

        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'approve'])->assertAccepted();

        $amount = $this->getJson("/api/trips/{$id}")->assertJsonPath('data.pending.type', 'payment')->json('data.pending.amount');
        self::assertIsNumeric($amount);

        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'authorize', 'amount' => $amount])->assertAccepted();

        $this->getJson("/api/trips/{$id}")
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.outcome', 'booked')
            ->assertJsonCount(2, 'data.summary.bookings');

        self::assertCount(2, $this->gateway->ledger());
    }

    public function test_feedback_reaches_the_next_proposal(): void
    {
        $this->script(
            ...$this->intake(),
            ...$this->window('Cancún', '2027-02-10'),
            ...[new AssistantMessage('{"earliest_start":"","latest_start":"","nights":0}')],
            ...$this->window('Kyoto', '2027-04-01'),
        );

        $id = $this->postJson('/api/trips', ['ask' => 'Somewhere nice from Milan, two of us, 10 nights.'])->json('data.id');
        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'revise', 'feedback' => 'Japan in spring instead'])->assertAccepted();

        $this->getJson("/api/trips/{$id}")
            ->assertJsonPath('data.pending.details.name', 'Kyoto, Japan')
            ->assertJsonPath('data.summary.feedback.window.0', 'Japan in spring instead');
    }

    public function test_an_answer_must_fit_the_question(): void
    {
        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'));
        $id = $this->postJson('/api/trips', ['ask' => 'Best time for Mexico? Two of us from Milan.'])->json('data.id');

        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'authorize', 'amount' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision');

        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'revise'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('feedback');
    }

    public function test_a_trip_that_is_not_waiting_refuses_answers(): void
    {
        $trip = Trip::create(['ask' => 'anything at all', 'status' => Trip::WORKING]);

        $this->postJson("/api/trips/{$trip->id}/answer", ['decision' => 'approve'])->assertConflict();
        $this->postJson("/api/trips/{$trip->id}/retry")->assertConflict();
    }

    public function test_an_expired_payment_hold_is_settled_with_nothing_booked(): void
    {
        // A one-second hold, and a real wait: the deadline lives in the
        // workflow's persisted request and is checked against the real clock.
        config(['trips.authorization_window' => '+1 second']);
        $this->app->forgetInstance(\App\Trips\TripRunner::class);

        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'), ...$this->offers());
        $id = $this->postJson('/api/trips', ['ask' => 'Best time for Mexico? Two of us from Milan.'])->json('data.id');
        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'approve']);
        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'approve']);
        $this->getJson("/api/trips/{$id}")->assertJsonPath('data.pending.type', 'payment');

        Carbon::setTestNow();
        \sleep(2);

        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'authorize', 'amount' => 1])->assertConflict();

        $this->getJson("/api/trips/{$id}")
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.outcome', 'authorization_expired');

        self::assertSame([], $this->gateway->ledger());
    }

    public function test_the_scheduled_command_settles_expired_holds_nobody_answered(): void
    {
        config(['trips.authorization_window' => '+1 second']);
        $this->app->forgetInstance(\App\Trips\TripRunner::class);

        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'), ...$this->offers());
        $id = $this->postJson('/api/trips', ['ask' => 'Best time for Mexico? Two of us from Milan.'])->json('data.id');
        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'approve']);
        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'approve']);

        Carbon::setTestNow();
        \sleep(2);

        $command = $this->artisan('trips:settle-expired');
        self::assertInstanceOf(\Illuminate\Testing\PendingCommand::class, $command);
        $command->expectsOutput('Settled 1 expired trip(s).')->assertSuccessful()->run();

        $this->getJson("/api/trips/{$id}")->assertJsonPath('data.outcome', 'authorization_expired');
    }

    public function test_a_failed_trip_is_retried_without_booking_twice(): void
    {
        $this->bind(failNextHotelCalls: 1);
        $this->script(...$this->intake(), ...$this->window('Cancún', '2027-02-10'), ...$this->offers());

        $id = $this->postJson('/api/trips', ['ask' => 'Best time for Mexico? Two of us from Milan.'])->json('data.id');
        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'approve']);
        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'approve']);
        $amount = $this->getJson("/api/trips/{$id}")->json('data.pending.amount');
        $this->postJson("/api/trips/{$id}/answer", ['decision' => 'authorize', 'amount' => $amount]);

        $this->getJson("/api/trips/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Hotel booking service timed out.');

        $this->postJson("/api/trips/{$id}/retry")->assertAccepted();

        $this->getJson("/api/trips/{$id}")->assertJsonPath('data.outcome', 'booked');
        self::assertCount(2, $this->gateway->ledger(), 'One flight and one hotel - the flight was not booked twice.');
    }

    public function test_the_spa_is_served_for_client_side_routes(): void
    {
        $this->withoutVite();

        $this->get('/')->assertOk()->assertSee('<div id="app"></div>', false);
        $this->get('/trips/anything')->assertOk();
        $this->getJson('/api/trips/does-not-exist')->assertNotFound();
    }

    // --- helpers ---------------------------------------------------------------

    private function bind(int $failNextHotelCalls = 0): void
    {
        $this->gateway = new SandboxBookingGateway("{$this->dir}/bookings.json", failNextHotelCalls: $failNextHotelCalls);

        $this->app->instance(TripServices::class, new TripServices(
            provider: $this->provider,
            places: $this->places ??= new FixedPlaces(),
            climate: new FixedClimate(),
            inventory: new SandboxInventory("{$this->dir}/offers.json"),
            bookings: $this->gateway,
        ));
        $this->app->forgetInstance(\App\Trips\TripRunner::class);
    }

    private ?FixedPlaces $places = null;

    private function script(Message ...$messages): void
    {
        $this->provider->addResponses(...$messages);
    }

    /** @return list<Message> */
    private function intake(): array
    {
        return [
            new AssistantMessage('{"origin_city":"Milan","origin_country_code":"IT","travellers":2,"nights":10,"budget":6000,"preferences":""}'),
            new AssistantMessage('{"earliest_start":"","latest_start":"","nights":0}'),
        ];
    }

    /** @return list<Message> */
    private function window(string $city, string $start): array
    {
        $place = self::place($city);

        return [
            new ToolCallMessage(null, [ToolCall::make('get_monthly_climate', 'call_' . \bin2hex(\random_bytes(3)), ['city' => $place->name, 'country_code' => $place->countryCode])]),
            new AssistantMessage(\json_encode([
                'destination_place_id' => $place->id,
                'start_date' => $start,
                'weather_summary' => 'Warm and dry.',
                'reasoning' => 'The dry season.',
            ], \JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return list<Message> */
    private function offers(): array
    {
        $inventory = new SandboxInventory("{$this->dir}/offers.json");
        $flight = $inventory->searchFlights(self::place('Milan'), self::place('Cancún'), '2027-02-10', '2027-02-20', 2)[1];
        $hotel = $inventory->searchHotels(self::place('Cancún'), '2027-02-10', '2027-02-20', 2)[0];

        return [
            new ToolCallMessage(null, [
                ToolCall::make('search_flights', 'call_' . \bin2hex(\random_bytes(3)), []),
                ToolCall::make('search_hotels', 'call_' . \bin2hex(\random_bytes(3)), []),
            ]),
            new AssistantMessage(\json_encode([
                'flight_offer_id' => $flight->id,
                'hotel_offer_id' => $hotel->id,
                'reasoning' => 'Good value.',
            ], \JSON_THROW_ON_ERROR)),
        ];
    }

    private static function place(string $name): Place
    {
        return \array_first(\array_filter(FixedPlaces::defaults(), static fn (Place $p): bool => $p->name === $name))
            ?? throw new \LogicException("No fixture place {$name}");
    }
}
