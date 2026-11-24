<?php

declare(strict_types=1);

namespace App\Domain\International\Contracts;

use App\Domain\International\Enums\ScreeningOutcome;

/**
 * Result of one screening check. Immutable value object.
 */
final readonly class ScreeningResult
{
    public function __construct(
        public ScreeningOutcome $outcome,
        public string $provider,
        public int $matches = 0,
        public string $detail = '',
    ) {
    }

    /** Whether this outcome may move a transfer out of screening by itself. */
    public function isDecisive(): bool
    {
        return $this->outcome->isDecisive();
    }
}
