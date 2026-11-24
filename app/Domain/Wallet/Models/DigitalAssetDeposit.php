<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * One observed on-chain deposit (tx hash). Idempotent on
 * (network, tx_hash): a replayed webhook / re-scan returns the same row
 * and never credits twice. Credited only once confirmations reach the
 * network requirement, via AssetTransactionAggregate::credit().
 */
class DigitalAssetDeposit extends Model
{
    use UsesTenantConnection;

    protected $table = 'digital_asset_deposits';

    protected $guarded = [];

    protected $casts = [
        'confirmations'          => 'integer',
        'required_confirmations' => 'integer',
        'credited_at'            => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CREDITED = 'credited';

    public const STATUS_FAILED = 'failed';
}
