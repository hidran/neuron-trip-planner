<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Requests;

use DateTimeImmutable;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;

/**
 * "Authorise me to spend exactly this much, before this deadline."
 *
 * Two properties make this safer than a yes/no button:
 *
 * - the answer must repeat the amount. An authorisation is for a figure, not
 *   for "whatever it costs now", so a price that moved since the traveller
 *   looked cannot slip through;
 * - it expires. Fares are held for minutes, not days: once the deadline has
 *   passed, an inputless run(ExecutionRequest::resume()) delivers null to the node, which
 *   ends the trip instead of booking at a stale price.
 *
 * Answer with ['decision' => 'authorize', 'amount' => 1234.56] or
 * ['decision' => 'decline'].
 */
class PaymentAuthorizationRequest extends WaitForEventRequest
{
    public const EVENT = 'trip.payment';

    /**
     * @param list<array{description: string, amount: float}> $lines
     */
    public function __construct(
        protected float $amount,
        protected string $currency,
        protected array $lines,
        DateTimeImmutable $expiresAt,
    ) {
        parent::__construct(self::EVENT, $expiresAt);
    }

    public function getMessage(): string
    {
        return \sprintf('Authorise payment of %.2f %s', $this->amount, $this->currency);
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @return list<array{description: string, amount: float}>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadata(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'lines' => $this->lines,
            'expiresAt' => $this->getExpiresAt()?->format(DATE_ATOM),
        ];
    }
}
