<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tests;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronBook\TripPlanner\Geo\FixedPlaces;
use NeuronBook\TripPlanner\Requests\DecisionRequest;
use NeuronBook\TripPlanner\TripWorkflow;
use NeuronBook\TripPlannerLive\LiveServices;
use NeuronBook\TripPlannerLive\Tests\Support\ScriptedHttp;
use PHPUnit\Framework\TestCase;

/**
 * The whole point, end to end: the unchanged core workflow, a scripted model,
 * and an advisor that calls a real-API tool - here against the scripted
 * network - before it proposes a destination.
 */
final class AgentWithRealToolsTest extends TestCase
{
    private string $dir;
    private FakeAIProvider $provider;
    private ScriptedHttp $http;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/live-agent-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0775, true);
        $this->provider = new FakeAIProvider();
        $this->http = new ScriptedHttp();
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function testTheAdvisorChecksHolidaysOnlineBeforeProposingKyoto(): void
    {
        $this->http
            ->route('#PublicHolidays/2027/JP#', 'nager-jp-2027')
            ->route('#restcountries#', 'restcountries-jp');
        $kyoto = \array_first(new FixedPlaces()->search('Kyoto')) ?? throw new \LogicException();

        $this->provider->addResponses(
            // Intake, then the date extraction: no dates stated.
            new AssistantMessage('{"origin_city":"Milan","origin_country_code":"IT","travellers":2,"nights":10,"budget":5000,"preferences":"temples and food"}'),
            new AssistantMessage('{"earliest_start":"","latest_start":"","nights":0}'),
            // The advisor: climate, holidays and country facts in one round of tool calls...
            new ToolCallMessage(null, [
                ToolCall::make('get_monthly_climate', 'c1', ['city' => 'Kyoto', 'country_code' => 'JP']),
                ToolCall::make('get_public_holidays', 'c2', ['country_code' => 'JP', 'start_date' => '2027-04-27', 'end_date' => '2027-05-07']),
                ToolCall::make('get_country_info', 'c3', ['country_code' => 'JP']),
            ]),
            // ...then the proposal, citing what the tools returned.
            new AssistantMessage(\json_encode([
                'destination_place_id' => $kyoto->id,
                'start_date' => '2027-04-27',
                'weather_summary' => 'Mild and mostly dry, around 22 C.',
                'reasoning' => 'Cherry blossom is over and the crowds thin out - but 29 April to 5 May is Golden Week, five public holidays when Japanese families travel. Currency is the yen.',
            ], \JSON_THROW_ON_ERROR)),
        );

        $state = $this->workflow()->run();

        self::assertTrue($state->isInterrupted());
        $request = $state->getInterruptRequest();
        self::assertInstanceOf(DecisionRequest::class, $request);
        self::assertSame('window', $request->getStage());
        self::assertStringStartsWith('Kyoto, Japan', $request->getMessage());
        self::assertStringContainsString('Golden Week', $request->getDetails()['reasoning']);

        // Two online services were really called, once each, with the model's arguments.
        self::assertSame(1, $this->http->called('PublicHolidays/2027/JP'));
        self::assertSame(1, $this->http->called('restcountries.com/v3.1/alpha/JP'));
    }

    public function testTheModelSawTheToolsTheirResultsAndTheRulesForUsingThem(): void
    {
        $this->http->route('#PublicHolidays/2027/JP#', 'nager-jp-2027');
        $kyoto = \array_first(new FixedPlaces()->search('Kyoto')) ?? throw new \LogicException();

        $this->provider->addResponses(
            new AssistantMessage('{"origin_city":"Milan","origin_country_code":"IT","travellers":2,"nights":10,"budget":5000,"preferences":"temples"}'),
            new AssistantMessage('{"earliest_start":"","latest_start":"","nights":0}'),
            new ToolCallMessage(null, [
                ToolCall::make('get_monthly_climate', 'c1', ['city' => 'Kyoto', 'country_code' => 'JP']),
                ToolCall::make('get_public_holidays', 'c2', ['country_code' => 'JP', 'start_date' => '2027-04-27', 'end_date' => '2027-05-07']),
            ]),
            new AssistantMessage(\json_encode(['destination_place_id' => $kyoto->id, 'start_date' => '2027-04-27', 'weather_summary' => 'Mild.', 'reasoning' => 'Golden Week.'], \JSON_THROW_ON_ERROR)),
        );

        $this->workflow()->run();

        // The advisor's calls: it was offered the research tools...
        $this->provider->assertSent(static function (RequestRecord $r): bool {
            $names = \array_map(static fn ($t): string => $t->getName(), $r->tools);

            return \in_array('get_public_holidays', $names, true) && \in_array('get_monthly_climate', $names, true);
        });
        // ...was given the toolkit's rules in its instructions...
        $this->provider->assertSent(static fn (RequestRecord $r): bool => \str_contains((string) $r->systemPrompt?->getContent(), 'only source of facts about the world'));
        // ...and its last request carried the real holiday data back to it.
        $this->provider->assertSent(static fn (RequestRecord $r): bool => \array_any(
            $r->messages,
            static fn (Message $m): bool => \str_contains(self::text($m), 'Children\'s Day'),
        ));
    }

    public function testADeadHolidayServiceDoesNotStopTheTrip(): void
    {
        $this->http->fail('#PublicHolidays#', 503);
        $kyoto = \array_first(new FixedPlaces()->search('Kyoto')) ?? throw new \LogicException();

        $this->provider->addResponses(
            new AssistantMessage('{"origin_city":"Milan","origin_country_code":"IT","travellers":2,"nights":10,"budget":5000,"preferences":"temples"}'),
            new AssistantMessage('{"earliest_start":"","latest_start":"","nights":0}'),
            new ToolCallMessage(null, [
                ToolCall::make('get_monthly_climate', 'c1', ['city' => 'Kyoto', 'country_code' => 'JP']),
                ToolCall::make('get_public_holidays', 'c2', ['country_code' => 'JP', 'start_date' => '2027-04-27', 'end_date' => '2027-05-07']),
            ]),
            new AssistantMessage(\json_encode(['destination_place_id' => $kyoto->id, 'start_date' => '2027-04-27', 'weather_summary' => 'Mild.', 'reasoning' => 'I could not check public holidays, so verify them before booking.'], \JSON_THROW_ON_ERROR)),
        );

        $state = $this->workflow()->run();

        self::assertTrue($state->isInterrupted(), 'The trip reached the traveller: ' . \var_export($state->outcome(), true));
        $this->provider->assertSent(static fn (RequestRecord $r): bool => \array_any(
            $r->messages,
            static fn (Message $m): bool => \str_contains(self::text($m), 'did not answer'),
        ));
    }

    private function workflow(): TripWorkflow
    {
        return TripWorkflow::make(
            tripId: 'live1',
            services: LiveServices::create($this->dir, $this->provider, [], $this->http, new FixedPlaces()),
            ask: 'Best time to visit Japan? Two of us from Milan, 10 nights, about 5000 euros, temples and food.',
            today: '2026-09-26',
        )->setPersistence(new FilePersistence("{$this->dir}/workflows"));
    }

    private static function text(Message $message): string
    {
        // A tool result lives in the message's blocks, not its text: serialise the whole message.
        return \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
