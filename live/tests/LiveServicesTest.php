<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tests;

use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronBook\TripPlanner\Agents\OfferScoutAgent;
use NeuronBook\TripPlanner\Agents\SeasonAdvisorAgent;
use NeuronBook\TripPlanner\Geo\FixedPlaces;
use NeuronBook\TripPlanner\Travel\SandboxInventory;
use NeuronBook\TripPlannerLive\Amadeus\AmadeusInventory;
use NeuronBook\TripPlannerLive\LiveServices;
use NeuronBook\TripPlannerLive\Tests\Support\ScriptedHttp;
use NeuronBook\TripPlannerLive\TravelResearchToolkit;
use PHPUnit\Framework\TestCase;

/**
 * Which capabilities each agent is handed: the whole point of the live version.
 */
final class LiveServicesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/live-services-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->dir));
    }

    public function testWithoutKeysFlightsAndHotelsAreTheSandbox(): void
    {
        self::assertInstanceOf(SandboxInventory::class, $this->services()->inventory);
        self::assertFalse(LiveServices::hasAmadeus([]));
    }

    public function testBothAmadeusKeysSwitchOnRealOffers(): void
    {
        $services = $this->services(['AMADEUS_CLIENT_ID' => 'id', 'AMADEUS_CLIENT_SECRET' => 'secret']);

        self::assertInstanceOf(AmadeusInventory::class, $services->inventory);
        self::assertFalse(LiveServices::hasAmadeus(['AMADEUS_CLIENT_ID' => 'id']), 'One key is not enough.');
    }

    public function testTheAdvisorGetsAllSevenResearchTools(): void
    {
        self::assertSame([
            'get_country_info',
            'convert_currency',
            'get_public_holidays',
            'get_travel_advisory',
            'get_weather_forecast',
            'get_attractions',
            'get_destination_guide',
        ], self::names($this->services()->agentTools[SeasonAdvisorAgent::class]));
    }

    public function testTheScoutGetsOnlyRatesAndHolidays(): void
    {
        self::assertSame(
            ['convert_currency', 'get_public_holidays'],
            self::names($this->services()->agentTools[OfferScoutAgent::class]),
            'A narrower toolbox is a more reliable agent.',
        );
    }

    public function testWebSearchIsAddedOnlyWhenAKeyIsSet(): void
    {
        $without = $this->services()->agentTools[SeasonAdvisorAgent::class];
        $with = $this->services(['TAVILY_API_KEY' => 'tvly-test'])->agentTools[SeasonAdvisorAgent::class];

        self::assertCount(1, $without);
        self::assertCount(2, $with);
        self::assertInstanceOf(TavilyToolkit::class, $with[1]);
        self::assertContains('web_search', self::names([$with[1]]));
    }

    public function testWiringAnAgentAttachesItsTools(): void
    {
        $services = $this->services();

        $advisor = $services->wire(SeasonAdvisorAgent::make(workflowId: 'w:advisor'));
        $scout = $services->wire(OfferScoutAgent::make(workflowId: 'w:scout'));

        self::assertContains('get_public_holidays', self::names($advisor->getTools()));
        self::assertNotContains('get_attractions', self::names($scout->getTools()));
        self::assertContains('convert_currency', self::names($scout->getTools()));
    }

    public function testTheToolkitCarriesItsOwnUsageRules(): void
    {
        $toolkit = new TravelResearchToolkit(new FixedPlaces(), \NeuronBook\TripPlannerLive\Api\LiveApis::over(new \NeuronBook\TripPlannerLive\Api\Http(new ScriptedHttp())));

        self::assertStringContainsString('only source of facts about the world', (string) $toolkit->guidelines());
        self::assertStringContainsString('not a reason to guess', (string) $toolkit->guidelines());
    }

    /**
     * @param array<string, string> $env
     */
    private function services(array $env = []): \NeuronBook\TripPlanner\TripServices
    {
        return LiveServices::create($this->dir, new FakeAIProvider(), $env, new ScriptedHttp(), new FixedPlaces());
    }

    /**
     * @param iterable<ToolInterface|ToolkitInterface> $tools
     * @return list<string>
     */
    private static function names(iterable $tools): array
    {
        $names = [];

        foreach ($tools as $tool) {
            foreach ($tool instanceof ToolkitInterface ? $tool->tools() : [$tool] as $t) {
                $names[] = $t->getName();
            }
        }

        return $names;
    }
}
