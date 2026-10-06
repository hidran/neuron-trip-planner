<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronBook\TripPlanner\Agents\IntakeAgent;
use NeuronBook\TripPlanner\Agents\SeasonAdvisorAgent;
use NeuronBook\TripPlanner\Booking\SandboxBookingGateway;
use NeuronBook\TripPlanner\Climate\FixedClimate;
use NeuronBook\TripPlanner\Geo\FixedPlaces;
use NeuronBook\TripPlanner\Travel\SandboxInventory;
use NeuronBook\TripPlanner\TripServices;
use PHPUnit\Framework\TestCase;

/**
 * The extension point the live version builds on: extra tools per agent class.
 */
final class TripServicesTest extends TestCase
{
    public function testExtraToolsReachTheAgentsTheyAreRegisteredFor(): void
    {
        $extra = new class () extends Tool {
            protected string $name = 'extra_tool';

            protected ?string $description = 'Only for advisors.';

            public function __invoke(): string
            {
                return 'ok';
            }
        };

        $services = new TripServices(
            provider: new FakeAIProvider(),
            places: new FixedPlaces(),
            climate: new FixedClimate(),
            inventory: new SandboxInventory(\sys_get_temp_dir() . '/ts-offers.json'),
            bookings: new SandboxBookingGateway(\sys_get_temp_dir() . '/ts-bookings.json'),
            agentTools: [SeasonAdvisorAgent::class => [$extra]],
        );

        $advisor = $services->wire(SeasonAdvisorAgent::make(workflowId: 'w:advisor'));
        $intake = $services->wire(IntakeAgent::make(workflowId: 'w:intake'));

        self::assertContains('extra_tool', self::names($advisor));
        self::assertNotContains('extra_tool', self::names($intake));
    }

    public function testWithoutExtraToolsWiringOnlySetsTheProvider(): void
    {
        $services = new TripServices(
            provider: new FakeAIProvider(),
            places: new FixedPlaces(),
            climate: new FixedClimate(),
            inventory: new SandboxInventory(\sys_get_temp_dir() . '/ts-offers.json'),
            bookings: new SandboxBookingGateway(\sys_get_temp_dir() . '/ts-bookings.json'),
        );

        self::assertSame([], $services->wire(SeasonAdvisorAgent::make(workflowId: 'w:advisor'))->getTools());
    }

    /**
     * @return list<string>
     */
    private static function names(Agent $agent): array
    {
        return \array_map(static fn ($t): string => $t->getName(), $agent->getTools());
    }
}
