<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per external-bank-account application.
 *
 * Statuses: pending → approved | rejected | closed | requires_manual_review.
 * The account number / IBAN are NEVER stored here — they live on the
 * persisted BankAccountModel (encrypted) and come only from the provider's
 * response. A rejected application never produces a bank account row.
 *
 * @property string $id
 * @property string $user_uuid
 * @property string $bank_code
 * @property string $provider
 * @property string $account_type
 * @property string $currency
 * @property string $status
 * @property string $idempotency_key
 * @property string|null $external_id
 * @property string|null $rejection_reason
 * @property array<string, mixed>|null $metadata
 */
class ExternalBankAccountApplication extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'external_bank_account_applications';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REQUIRES_MANUAL_REVIEW = 'requires_manual_review';

    protected $fillable = [
        'user_uuid',
        'bank_code',
        'provider',
        'account_type',
        'currency',
        'status',
        'idempotency_key',
        'external_id',
        'rejection_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
