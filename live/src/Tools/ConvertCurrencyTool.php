<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Tools;

use InvalidArgumentException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronBook\TripPlannerLive\Api\ApiUnavailable;
use NeuronBook\TripPlannerLive\Api\Frankfurter;

/**
 * Today's reference exchange rate, so the model never converts from memory.
 *
 * Language models know what an exchange rate is and have no idea what it is
 * today. Letting one convert 6000 EUR into yen "from experience" produces a
 * confident, plausible, wrong number. This tool is the cure, and the same
 * pattern fixes any figure that changes: look it up, do not recall it.
 */
class ConvertCurrencyTool extends LiveTool
{
    protected string $name = 'convert_currency';

    protected ?string $description = 'Converts an amount between two currencies at the latest European Central Bank '
        . 'reference rate. Always use it instead of converting from memory, because exchange rates change daily. '
        . 'Covers about thirty major currencies; for others it says so.';

    public function __construct(private readonly Frankfurter $rates)
    {
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'amount', type: PropertyType::NUMBER, description: 'The amount to convert.', required: true),
            new ToolProperty(name: 'from', type: PropertyType::STRING, description: 'ISO 4217 code of the starting currency. Example: "EUR".', required: true),
            new ToolProperty(name: 'to', type: PropertyType::STRING, description: 'ISO 4217 code of the target currency. Example: "JPY".', required: true),
        ];
    }

    public function __invoke(float $amount, string $from, string $to): string|ToolOutput
    {
        try {
            return self::json($this->rates->convert($amount, $from, $to));
        } catch (InvalidArgumentException $e) {
            return ToolOutput::error($e->getMessage());
        } catch (ApiUnavailable $e) {
            return \in_array($e->status, [404, 422], true)
                ? ToolOutput::error("The reference rates do not include {$from} or {$to}. Say that you could not convert it; do not estimate.")
                : self::unavailable('exchange-rate', $e);
        }
    }
}
