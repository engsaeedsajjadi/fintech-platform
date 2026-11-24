<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class CardBlocked
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $cardToken,
        public readonly ?string $reason = null,
    ) {
    }
}
