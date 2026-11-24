<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/** Record of one internal fiat transfer between two wallet accounts. */
class FiatTransfer extends Model
{
    use UsesTenantConnection;

    protected $table = 'fiat_transfers';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:4',
        'fee'    => 'decimal:4',
    ];

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERSED = 'reversed';
}
