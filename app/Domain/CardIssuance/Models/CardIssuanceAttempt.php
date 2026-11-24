<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per card-issuance attempt, keyed by idempotency key.
 *
 * Statuses: pending → succeeded | failed | requires_manual_review.
 * A failed attempt is NEVER recorded as an issued card: the failure and the
 * provider error are stored here, and an admin retries (same idempotency
 * key) or rejects it from the manual-review queue.
 *
 * @property string $id
 * @property string $user_id
 * @property string $issuer
 * @property string $idempotency_key
 * @property string $status
 * @property string|null $card_token
 * @property string|null $provider_reference
 * @property string|null $error
 * @property array<string, mixed>|null $metadata
 */
class CardIssuanceAttempt extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'card_issuance_attempts';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REQUIRES_MANUAL_REVIEW = 'requires_manual_review';

    protected $fillable = [
        'user_id',
        'issuer',
        'idempotency_key',
        'status',
        'card_token',
        'provider_reference',
        'error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function requiresManualReview(): bool
    {
        return $this->status === self::STATUS_REQUIRES_MANUAL_REVIEW;
    }
}
