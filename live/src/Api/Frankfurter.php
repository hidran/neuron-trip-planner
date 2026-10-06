<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Api;

use InvalidArgumentException;

/**
 * Exchange rates from frankfurter.dev, which republishes the European Central
 * Bank's daily reference rates - free, no key. About thirty currencies: enough
 * for a trip, and honest about the ones it does not know.
 */
final class Frankfurter
{
    private const string ENDPOINT = 'https://api.frankfurter.dev/v1/latest';

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * @return array{amount: float, from: string, to: string, rate: float, converted: float, date: string}
     * @throws InvalidArgumentException for something that is not a 3-letter currency code
     * @throws ApiUnavailable a 404 or 422 status means the bank does not publish that currency
     */
    public function convert(float $amount, string $from, string $to): array
    {
        $from = \strtoupper(\trim($from));
        $to = \strtoupper(\trim($to));

        foreach ([$from, $to] as $code) {
            if (\preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                throw new InvalidArgumentException("\"{$code}\" is not an ISO 4217 currency code such as EUR or JPY.");
            }
        }

        if ($from === $to) {
            return ['amount' => $amount, 'from' => $from, 'to' => $to, 'rate' => 1.0, 'converted' => $amount, 'date' => \date('Y-m-d')];
        }

        $data = $this->http->getJson(self::ENDPOINT, ['base' => $from, 'symbols' => $to]);

        /** @var array<string, float|int> $rates */
        $rates = $data['rates'] ?? [];

        if (!isset($rates[$to])) {
            throw new ApiUnavailable("The bank publishes no rate from {$from} to {$to}", 404);
        }

        $rate = (float) $rates[$to];

        return [
            'amount' => $amount,
            'from' => $from,
            'to' => $to,
            'rate' => $rate,
            'converted' => \round($amount * $rate, 2),
            'date' => (string) ($data['date'] ?? ''),
        ];
    }
}
