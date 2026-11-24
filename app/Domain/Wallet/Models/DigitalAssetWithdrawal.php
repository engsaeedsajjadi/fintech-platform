<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * A digital-asset withdrawal request: limits + approval + on-chain
 * confirmation tracking. The debit happens only in execute(), after
 * approval, via AssetTransactionAggregate::debit(); the tx hash and
 * confirmations are tracked here afterwards.
 */
class DigitalAssetWithdrawal extends Model
{
    use UsesTenantConnection;

    protected $table = 'digital_asset_withdrawals';

    protected $guarded = [];

    protected $casts = [
        'amount_minor'           => 'integer',
        'fee_minor'              => 'integer',
        'confirmations'          => 'integer',
        'required_confirmations' => 'integer',
        'requested_at'           => 'datetime',
        'reviewed_at'            => 'datetime',
        'broadcast_at'           => 'datetime',
    ];

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_BROADCAST = 'broadcast';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';
}
