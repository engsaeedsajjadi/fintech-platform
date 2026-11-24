<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Exceptions\LimitExceededException;
use App\Domain\Wallet\Models\Conversion;
use App\Domain\Wallet\Models\DigitalAssetWithdrawal;
use App\Domain\Wallet\Models\FiatDeposit;
use App\Domain\Wallet\Models\FiatTransfer;
use App\Domain\Wallet\Models\FiatWithdrawal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * Configurable limits, enforced in the services (never only in the UI).
 * Fiat limits are decimal strings in major units; digital limits are
 * minor-unit integers. Daily windows are calendar days. Amounts already
 * booked today are summed from the intent tables (completed + in-flight
 * states count, so a limit cannot be bypassed by leaving requests pending).
 */
final class WalletLimitsService
{
    public function checkFiatDeposit(string $accountUuid, string $currency, string $amount): void
    {
        $limits = (array) config('wallet.limits.fiat', []);
        $this->checkPerTransaction($amount, (string) ($limits['deposit_per_transaction'] ?? '0'), 'fiat deposit');
        $this->checkDailySum(
            $amount,
            (string) ($limits['deposit_per_day'] ?? '0'),
            'fiat deposit',
            DecimalMath::exactSum(FiatDeposit::query()
                ->where('account_uuid', $accountUuid)->where('currency', $currency)
                ->where('status', FiatDeposit::STATUS_COMPLETED)
                ->whereDate('created_at', Carbon::today())
                ->pluck('amount')->all())
        );
    }

    public function checkFiatWithdrawal(string $accountUuid, string $currency, string $amount): void
    {
        $limits = (array) config('wallet.limits.fiat', []);
        $this->checkPerTransaction($amount, (string) ($limits['withdrawal_per_transaction'] ?? '0'), 'fiat withdrawal');
        $this->checkDailySum(
            $amount,
            (string) ($limits['withdrawal_per_day'] ?? '0'),
            'fiat withdrawal',
            DecimalMath::exactSum(FiatWithdrawal::query()
                ->where('account_uuid', $accountUuid)->where('currency', $currency)
                ->whereIn('status', [FiatWithdrawal::STATUS_PENDING_APPROVAL, FiatWithdrawal::STATUS_APPROVED, FiatWithdrawal::STATUS_COMPLETED])
                ->whereDate('created_at', Carbon::today())
                ->pluck('amount')->all())
        );
    }

    public function checkFiatTransfer(string $accountUuid, string $currency, string $amount): void
    {
        $limits = (array) config('wallet.limits.fiat', []);
        $this->checkPerTransaction($amount, (string) ($limits['transfer_per_transaction'] ?? '0'), 'fiat transfer');
        $this->checkDailySum(
            $amount,
            (string) ($limits['transfer_per_day'] ?? '0'),
            'fiat transfer',
            DecimalMath::exactSum(FiatTransfer::query()
                ->where('from_account_uuid', $accountUuid)->where('currency', $currency)
                ->where('status', FiatTransfer::STATUS_COMPLETED)
                ->whereDate('created_at', Carbon::today())
                ->pluck('amount')->all())
        );
    }

    public function checkFiatConversion(string $accountUuid, string $amount): void
    {
        $limits = (array) config('wallet.limits.fiat', []);
        $this->checkPerTransaction($amount, (string) ($limits['conversion_per_transaction'] ?? '0'), 'fiat conversion');
        $this->checkDailySum(
            $amount,
            (string) ($limits['conversion_per_day'] ?? '0'),
            'fiat conversion',
            DecimalMath::exactSum(Conversion::query()
                ->where('account_uuid', $accountUuid)
                ->where('status', Conversion::STATUS_COMPLETED)
                ->whereDate('created_at', Carbon::today())
                ->pluck('from_amount')->all())
        );
    }

    public function checkDigitalWithdrawal(string $accountUuid, string $assetCode, int $amountMinor): void
    {
        $limits = (array) config('wallet.limits.digital', []);
        $perTxn = (int) ($limits['withdrawal_per_transaction_minor'] ?? 0);
        if ($perTxn > 0 && $amountMinor > $perTxn) {
            throw new LimitExceededException('digital withdrawal', (string) $perTxn, (string) $amountMinor);
        }
        $perDay = (int) ($limits['withdrawal_per_day_minor'] ?? 0);
        if ($perDay > 0) {
            $today = (int) DigitalAssetWithdrawal::query()
                ->where('account_uuid', $accountUuid)->where('asset_code', $assetCode)
                ->whereIn('status', [DigitalAssetWithdrawal::STATUS_PENDING_APPROVAL, DigitalAssetWithdrawal::STATUS_APPROVED, DigitalAssetWithdrawal::STATUS_BROADCAST, DigitalAssetWithdrawal::STATUS_CONFIRMED])
                ->whereDate('created_at', Carbon::today())
                ->sum('amount_minor');
            if ($today + $amountMinor > $perDay) {
                throw new LimitExceededException('digital withdrawal (daily)', (string) $perDay, (string) ($today + $amountMinor));
            }
        }
    }

    public function checkDigitalConversion(int $amountMinor): void
    {
        $limits = (array) config('wallet.limits.digital', []);
        $perTxn = (int) ($limits['conversion_per_transaction_minor'] ?? 0);
        if ($perTxn > 0 && $amountMinor > $perTxn) {
            throw new LimitExceededException('digital conversion', (string) $perTxn, (string) $amountMinor);
        }
    }

    private function checkPerTransaction(string $amount, string $limit, string $operation): void
    {
        if (BigDecimal::of($limit)->isPositive() && BigDecimal::of($amount)->compareTo(BigDecimal::of($limit)) > 0) {
            throw new LimitExceededException($operation, $limit, $amount);
        }
    }

    private function checkDailySum(string $amount, string $limit, string $operation, mixed $alreadyBookedToday): void
    {
        if (! BigDecimal::of($limit)->isPositive()) {
            return;
        }
        $total = BigDecimal::of((string) $alreadyBookedToday)->plus(BigDecimal::of($amount));
        if ($total->compareTo(BigDecimal::of($limit)) > 0) {
            throw new LimitExceededException("{$operation} (daily)", $limit, (string) $total);
        }
    }
}
