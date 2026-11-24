<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a card issuance attempt fails at the provider. The
 * attempt is left in `requires_manual_review` — never recorded as issued.
 * An admin resolves it via CardLifecycleService::resolveManualReview()
 * (retry with the same idempotency key, or mark as rejected).
 */
final class CardIssuanceFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $issuer,
        public readonly string $idempotencyKey,
        public readonly string $error,
    ) {
    }
}
