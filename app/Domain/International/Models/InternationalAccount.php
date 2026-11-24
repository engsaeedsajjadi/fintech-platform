<?php

declare(strict_types=1);

namespace App\Domain\International\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's multi-currency account for international transfers.
 *
 * One row per (user, currency). The balance is NEVER stored here: it is
 * derived from the double-entry ledger (journal lines of
 * `ledger_account_code`), so the account can never drift from the
 * ledger. See InternationalAccountService::balance().
 *
 * @property string $id
 * @property int $user_id
 * @property string $currency
 * @property string $ledger_account_code
 * @property string|null $nickname
 * @property string $status
 */
class InternationalAccount extends Model
{
    use HasUuids;

    protected $table = 'international_accounts';

    /** @var list<string> */
    protected $guarded = [];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FROZEN = 'frozen';

    public const STATUS_CLOSED = 'closed';

    /**
     * @return HasMany<InternationalTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(InternationalTransfer::class, 'international_account_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
