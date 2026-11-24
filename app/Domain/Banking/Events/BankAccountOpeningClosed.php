<?php

declare(strict_types=1);

namespace App\Domain\Banking\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class BankAccountOpeningClosed
{
    use Dispatchable;

    public function __construct(
        public readonly string $applicationId,
        public readonly string $userUuid,
        public readonly string $reason,
    ) {
    }
}
