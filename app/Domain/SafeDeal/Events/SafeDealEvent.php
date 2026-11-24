<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Events;

/**
 * Base class for SafeDeal domain events.
 *
 * Deliberately NOT Spatie ShouldBeStored: SafeDeal persists its history in
 * its own append-only safe_deal_events table (see SafeDealTradeAggregate),
 * instead of sharing AgentProtocol's stored-events tables. Events are plain
 * value objects dispatched through Laravel's dispatcher AND recorded
 * durably by the aggregate.
 */
abstract class SafeDealEvent
{
    public readonly string $occurredAt;

    public function __construct(
        public readonly string $tradeId,
        public readonly array $payload = [],
    ) {
        $this->occurredAt = now()->toIso8601String();
    }

    abstract public function name(): string;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event'       => $this->name(),
            'trade_id'    => $this->tradeId,
            'payload'     => $this->payload,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
