<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * A confirmed fiat deposit intent. The money movement itself is the
 * balanced LedgerService::post() entry referenced by journal_entry_id;
 * this row is the intent/audit record, never the balance.
 */
class FiatDeposit extends Model
{
    use UsesTenantConnection;

    protected $table = 'fiat_deposits';

    protected $guarded = [];

    protected $casts = [
        'amount'     => 'decimal:4',
        'fee'        => 'decimal:4',
        'net_amount' => 'decimal:4',
    ];

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERSED = 'reversed';
}
