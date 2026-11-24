<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\MoneyAmount;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Transfer validation: sender/recipient verification, limits, fee
 * calculation, and unique reference generation. Pure checks — this service
 * moves no money and posts no journal entry.
 */
final class TransferValidationService
{
    public function __construct(
        private readonly TransferFeeCalculator $fees,
    ) {
    }

    /**
     * Validate a transfer request and return the computed values.
     *
     * @return array{sender: LedgerAccount, recipient: LedgerAccount, amount: MoneyAmount, fee: array{fee_minor: int, fee_decimal: string, net_minor: int, gross_minor: int}, reference: string}
     *
     * @throws InvalidArgumentException on any failed check.
     */
    public function validate(
        string $senderAccountCode,
        string $recipientAccountCode,
        int $amountMinor,
        string $currency,
        ?string $reference = null,
    ): array {
        if ($senderAccountCode === $recipientAccountCode) {
            throw new InvalidArgumentException('Sender and recipient accounts must differ.');
        }

        $sender = $this->verifiedAccount($senderAccountCode, 'sender');
        $recipient = $this->verifiedAccount($recipientAccountCode, 'recipient');

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Transfer amount must be a positive integer in minor units.');
        }

        $maxAmount = (int) config('payment_rails.transfers.max_amount_minor', 100000000);

        if ($amountMinor > $maxAmount) {
            throw new InvalidArgumentException(
                "Transfer amount {$amountMinor} exceeds the per-transfer limit of {$maxAmount} minor units."
            );
        }

        $dailyLimit = (int) config('payment_rails.transfers.daily_limit_minor', 500000000);
        $spentToday = $this->spentTodayMinor($senderAccountCode, $currency);

        if ($spentToday + $amountMinor > $dailyLimit) {
            throw new InvalidArgumentException(
                "Transfer would exceed the daily limit of {$dailyLimit} minor units ({$spentToday} already sent today)."
            );
        }

        $amount = MoneyAmount::fromMinorUnits($amountMinor, $currency);
        $fee = $this->fees->calculateFromConfig($amountMinor, $currency);

        return [
            'sender'    => $sender,
            'recipient' => $recipient,
            'amount'    => $amount,
            'fee'       => $fee,
            'reference' => $reference ?? self::generateReference(),
        ];
    }

    /**
     * A ledger account is a valid transfer party only when it exists and is
     * active. (User-ownership checks live in the Account domain — e.g.
     * Account::findOwnedBy() — and the API layer must apply them before
     * calling this service.).
     */
    private function verifiedAccount(string $code, string $role): LedgerAccount
    {
        $account = LedgerAccount::where('code', $code)->first();

        if ($account === null) {
            throw new InvalidArgumentException("{$role} account '{$code}' does not exist.");
        }

        if (! $account->is_active) {
            throw new InvalidArgumentException("{$role} account '{$code}' is not active.");
        }

        return $account;
    }

    /**
     * Minor units already sent from this account today, from successful
     * PaymentTransaction rows (the sender ledger code is recorded in the
     * row's metadata by PaymentTransferService). Best-effort velocity
     * check; the ledger balance guard is the hard no-overdraw enforcement.
     */
    private function spentTodayMinor(string $senderAccountCode, string $currency): int
    {
        // Larastan cannot type-check JSON-arrow columns ('metadata->...'),
        // so the sender filter is applied in PHP after a plain query.
        $rows = \App\Domain\Payment\Models\PaymentTransaction::query()
            ->where('currency', strtoupper($currency))
            ->whereIn('status', ['successful', 'completed'])
            ->whereDate('created_at', today())
            ->get(['amount', 'metadata']);

        $total = 0;
        foreach ($rows as $row) {
            if (($row->metadata['sender_account_code'] ?? null) === $senderAccountCode) {
                $total += (int) $row->amount;
            }
        }

        return $total;
    }

    /** Globally unique payment reference (also UNIQUE in the DB). */
    public static function generateReference(): string
    {
        return 'pay_' . Str::ulid()->toString();
    }
}
