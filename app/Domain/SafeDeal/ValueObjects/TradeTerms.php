<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\ValueObjects;

/**
 * The agreed terms and conditions of a SafeDeal trade.
 *
 * Immutable once the agreement step is accepted: any later change creates
 * a new terms version and forces the trade back to AGREEMENT_PENDING.
 */
final readonly class TradeTerms
{
    /**
     * @param array<int, array{title: string, amount: string}> $milestones
     * @param array<string, mixed> $conditions
     */
    public function __construct(
        public string $description,
        public array $milestones,
        public array $conditions = [],
        public ?string $deliveryDeadline = null,
        public int $version = 1,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $version = 1): self
    {
        return new self(
            description: (string) ($data['description'] ?? ''),
            milestones: (array) ($data['milestones'] ?? []),
            conditions: (array) ($data['conditions'] ?? []),
            deliveryDeadline: isset($data['delivery_deadline']) ? (string) $data['delivery_deadline'] : null,
            version: $version,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description'       => $this->description,
            'milestones'        => $this->milestones,
            'conditions'        => $this->conditions,
            'delivery_deadline' => $this->deliveryDeadline,
            'version'           => $this->version,
        ];
    }
}
