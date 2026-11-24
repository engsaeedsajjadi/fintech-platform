<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\Exchange\Exceptions\InsufficientTradingBalanceException;
use App\Domain\Exchange\Models\TradingHold;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Funds reservation for trading (order book + OTC).
 *
 * Holds are separate rows in `trading_holds` — this service NEVER writes
 * to `account_balances.balance`. Available balance is derived as
 * `balance - SUM(active holds)`. Balances are stored in integer minor
 * units; hold amounts are major-unit decimals, converted with the asset's
 * `precision` (e.g. BTC 8, USDT 2).
 *
 * Every method must run inside the caller's DB transaction: the hold row
 * and the order/quote that owns it commit (or roll back) together, and the
 * balance row is locked FOR UPDATE so two concurrent reservations on the
 * same account serialise instead of double-spending.
 */
class TradingHoldService
{
    /**
     * Reserve funds for an order/quote. Returns the created hold.
     *
     * @throws InsufficientTradingBalanceException
     */
    public function reserve(
        string $accountId,
        string $assetCode,
        string $amount,
        string $sourceType,
        string $sourceId,
    ): TradingHold {
        if (BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            throw new InsufficientTradingBalanceException("Cannot reserve a non-positive amount of {$assetCode}.");
        }

        $existing = TradingHold::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('asset_code', $assetCode)
            ->active()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $account = $this->resolveAccount($accountId);

        // Lock the balance row so concurrent reservations serialise.
        $balanceRow = AccountBalance::where('account_uuid', $account->uuid ?? $accountId)
            ->where('asset_code', $assetCode)
            ->lockForUpdate()
            ->first();

        $available = $this->availableBalance($accountId, $assetCode, $balanceRow?->balance);

        if (BigDecimal::of($available)->isLessThan($amount)) {
            throw new InsufficientTradingBalanceException(
                "Insufficient available {$assetCode} balance: required {$amount}, available {$available}."
            );
        }

        return TradingHold::create([
            'hold_id'      => (string) Str::uuid(),
            'account_id'   => $accountId,
            'account_uuid' => $account?->uuid,
            'asset_code'   => $assetCode,
            'amount'       => BigDecimal::of($amount)->toScale(18)->__toString(),
            'source_type'  => $sourceType,
            'source_id'    => $sourceId,
            'status'       => 'active',
        ]);
    }

    /**
     * Available major-unit balance for an account/asset, net of active holds.
     */
    public function availableBalance(string $accountId, string $assetCode, ?int $balanceMinor = null): string
    {
        if ($balanceMinor === null) {
            $account = $this->resolveAccount($accountId);
            $balanceMinor = (int) (AccountBalance::where('account_uuid', $account->uuid ?? $accountId)
                ->where('asset_code', $assetCode)
                ->value('balance') ?? 0);
        }

        $precision = (int) (Asset::where('code', $assetCode)->value('precision') ?? 2);
        $major = BigDecimal::of((string) $balanceMinor)->dividedBy(bcpow('10', (string) $precision), 18);

        $held = TradingHold::forAccountAsset($accountId, $assetCode)
            ->active()
            ->sum('amount');

        return $major->minus(BigDecimal::of((string) $held))->toScale(18)->__toString();
    }

    /**
     * Reduce a hold by a consumed amount (partial fills). When the hold is
     * fully consumed it is marked consumed.
     */
    public function consume(TradingHold $hold, string $amount): TradingHold
    {
        $remaining = BigDecimal::of((string) $hold->amount)->minus($amount)->toScale(18, RoundingMode::DOWN)->__toString();

        if (BigDecimal::of($remaining)->isLessThanOrEqualTo(0)) {
            $hold->update(['amount' => '0', 'status' => 'consumed', 'consumed_at' => now()]);

            return $hold;
        }

        $hold->update(['amount' => BigDecimal::of($remaining)->toScale(18)->__toString()]);

        return $hold;
    }

    /** Release the whole remaining hold (cancel / expiry / rejection). */
    public function release(TradingHold $hold): TradingHold
    {
        if ($hold->status !== 'active') {
            return $hold;
        }

        $hold->update(['status' => 'released', 'released_at' => now()]);

        return $hold;
    }

    /** Release every active hold owned by a source (order id / quote id). */
    public function releaseForSource(string $sourceType, string $sourceId): void
    {
        TradingHold::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->active()
            ->get()
            ->each(fn (TradingHold $hold) => $this->release($hold));
    }

    public function activeHoldForSource(string $sourceType, string $sourceId, ?string $assetCode = null): ?TradingHold
    {
        $query = TradingHold::where('source_type', $sourceType)->where('source_id', $sourceId)->active();
        if ($assetCode !== null) {
            $query->where('asset_code', $assetCode);
        }

        return $query->first();
    }

    private function resolveAccount(string $accountId): ?Account
    {
        if (is_numeric($accountId)) {
            $account = Account::find($accountId);
            if ($account !== null) {
                return $account;
            }
        }

        return Account::where('uuid', $accountId)->first();
    }
}
