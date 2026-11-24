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
 * One row per manual-review decision on a KYC verification.
 *
 * Append-only by design: a decision is never edited in place. A later
 * decision is a new row; the latest row is the current decision. Together
 * with the AuditLog entries written by KycManualReviewService this is the
 * immutable history of who approved / rejected a verification, when, and why.
 */
class KycManualReview extends Model
{
    use UsesTenantConnection;
    use HasFactory;
    use HasUuids;

    public const DECISION_PENDING = 'pending';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    protected $fillable = [
        'kyc_verification_id',
        'user_id',
        'reviewer_id',
        'decision',
        'reason',
        'notes',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function verification(): BelongsTo
    {
        return $this->belongsTo(KycVerification::class, 'kyc_verification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isPending(): bool
    {
        return $this->decision === self::DECISION_PENDING;
    }
}
