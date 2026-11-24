<?php

declare(strict_types=1);

namespace App\Domain\Banking\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The provider outcome could not be classified (timeout, ambiguous
 * response). The application waits for a human decision — it is never
 * auto-approved or auto-rejected.
 */
final class BankAccountOpeningRequiresManualReview
{
    use Dispatchable;

    public function __construct(
        public readonly string $applicationId,
        public readonly string $userUuid,
        public readonly string $reason,
    ) {
    }
}
