<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * A digital-asset wallet account (one asset on one network for one
 * platform account). Holds NO key material: `custody_type` is
 * 'non-custodial' (device/Privy keys) or 'custodial' (HSM key reference
 * only — see CustodialKeyGate). The balance is the AccountBalance
 * projection for (account_uuid, asset_code); this row is the account
 * record, never the balance.
 */
class DigitalAssetAccount extends Model
{
    use UsesTenantConnection;

    protected $table = 'digital_asset_accounts';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING_ADDRESS = 'pending_address';

    public const STATUS_FROZEN = 'frozen';

    public const STATUS_CLOSED = 'closed';

    public const CUSTODY_NON_CUSTODIAL = 'non-custodial';

    public const CUSTODY_CUSTODIAL = 'custodial';
}
