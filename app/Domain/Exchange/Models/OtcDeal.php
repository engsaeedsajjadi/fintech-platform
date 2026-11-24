<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Settled OTC deal. One deal per accepted quote; settlement is recorded
 * in the ledger (see `ledger_entry_numbers`) and never via the matcher.
 */
class OtcDeal extends Model
{
    protected $table = 'otc_deals';

    protected $fillable = [
        'deal_id',
        'quote_id',
        'account_id',
        'side',
        'base_currency',
        'quote_currency',
        'amount',
        'price',
        'value',
        'fee',
        'status',
        'ledger_entry_numbers',
        'settled_at',
    ];

    protected $casts = [
        'ledger_entry_numbers' => 'array',
        'settled_at'           => 'datetime',
    ];
}
