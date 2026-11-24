<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw receipt of one provider deposit webhook, stored BEFORE any ledger
 * effect. Unique on (provider, provider_event_id): a duplicate delivery
 * is acknowledged and no-oped, never applied twice.
 */
class WalletDepositWebhook extends Model
{
    use UsesTenantConnection;

    protected $table = 'wallet_deposit_webhooks';

    protected $guarded = [];

    protected $casts = [
        'payload'    => 'array',
        'amount'     => 'decimal:4',
        'applied_at' => 'datetime',
    ];

    public const STATUS_APPLIED = 'applied';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_FAILED = 'failed';
}
