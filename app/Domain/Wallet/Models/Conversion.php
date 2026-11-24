<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * One executed conversion (the settlement record). Idempotent on
 * idempotency_key: replaying the same key returns this row. Amounts and
 * the rate are exact decimal strings; the rate is the one locked in the
 * quote, never re-fetched at execution.
 */
class Conversion extends Model
{
    use UsesTenantConnection;

    protected $table = 'conversions';

    protected $guarded = [];

    protected $casts = [
        'from_amount'       => 'decimal:18',
        'fee'               => 'decimal:18',
        'to_amount'         => 'decimal:18',
        'rate'              => 'string',
        'journal_entry_ids' => 'array',
    ];

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERSED = 'reversed';
}
