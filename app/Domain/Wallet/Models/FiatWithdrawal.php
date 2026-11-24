<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * A fiat withdrawal request with an approval workflow. Funds only move
 * (LedgerService::post) in execute(), after approval. Four-eyes: the
 * approver must differ from the requester.
 */
class FiatWithdrawal extends Model
{
    use UsesTenantConnection;

    protected $table = 'fiat_withdrawals';

    protected $guarded = [];

    protected $casts = [
        'amount'       => 'decimal:4',
        'fee'          => 'decimal:4',
        'net_amount'   => 'decimal:4',
        'requested_at' => 'datetime',
        'reviewed_at'  => 'datetime',
        'executed_at'  => 'datetime',
    ];

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';
}
