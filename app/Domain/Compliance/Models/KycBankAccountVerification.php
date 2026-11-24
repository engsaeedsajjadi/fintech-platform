<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bank-account ownership verification record.
 *
 * Stores ONLY the account holder name, the last 4 digits, and a SHA-256
 * hash of the full account identifier — never the full account number or
 * IBAN. Status is `pending` / `requires_manual_review` until a real
 * provider (or a human reviewer with bank evidence) confirms ownership.
 * A sandbox result can never move this row to `verified`.
 */
class KycBankAccountVerification extends Model
{
    use UsesTenantConnection;
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_REQUIRES_MANUAL_REVIEW = 'requires_manual_review';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'account_holder_name',
        'account_last4',
        'account_hash',
        'status',
        'provider',
        'provider_reference',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }
}
