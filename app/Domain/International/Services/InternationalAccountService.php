<?php

declare(strict_types=1);

namespace App\Domain\International\Services;

use App\Domain\International\Models\InternationalAccount;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Enums\EntryStatus;
use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Multi-currency accounts for international transfers.
 *
 * One account row per (user, currency). Opening an account also creates
 * its ledger account (`intl:account:{uuid}`) in the chart of accounts.
 * The ledger account is a LIABILITY (credit-normal): customer funds are
 * money the platform owes the customer, so funding credits it and a
 * transfer debits it. The balance is DERIVED from posted journal lines
 * in the account's normal direction — it is never stored, so it can
 * never drift from the ledger.
 */
final class InternationalAccountService
{
    /** ISO-4217 currencies this prototype may hold. Extend only with a treasury decision. */
    public const SUPPORTED_CURRENCIES = ['USD', 'EUR', 'GBP', 'AED', 'CHF', 'JPY', 'CAD', 'AUD', 'IRR'];

    public function __construct(
        private readonly InternationalAuditService $audit,
    ) {
    }

    public static function isSupportedCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true);
    }

    public function openAccount(User $user, string $currency, ?string $nickname = null): InternationalAccount
    {
        $currency = strtoupper($currency);

        if (! self::isSupportedCurrency($currency)) {
            throw new InvalidArgumentException("Currency '{$currency}' is not supported for international accounts.");
        }

        $existing = InternationalAccount::where('user_id', $user->id)
            ->where('currency', $currency)
            ->first();

        if ($existing !== null) {
            throw new InvalidArgumentException("User already has an international account in '{$currency}'.");
        }

        $id = (string) Str::uuid();
        $ledgerCode = "intl:account:{$id}";

        LedgerAccount::firstOrCreate(
            ['code' => $ledgerCode],
            ['name' => "International account {$currency}", 'type' => AccountType::LIABILITY, 'currency' => $currency],
        );

        $account = InternationalAccount::create([
            'id'                  => $id,
            'user_id'             => $user->id,
            'currency'            => $currency,
            'ledger_account_code' => $ledgerCode,
            'nickname'            => $nickname,
            'status'              => InternationalAccount::STATUS_ACTIVE,
        ]);

        $this->audit->log(null, (int) $user->id, 'international_account.opened', [
            'account_id'          => $account->id,
            'currency'            => $currency,
            'ledger_account_code' => $ledgerCode,
        ]);

        return $account;
    }

    /**
     * Ledger-derived balance in major units (decimal string, scale 4),
     * in the account's normal direction: credit − debit for this
     * liability (credit-normal) account. Posted + reversed entries are
     * included (a reversal's mirror nets the original out, exactly like
     * LedgerService::dbBalance()).
     */
    public function balance(InternationalAccount $account): string
    {
        $totals = $this->postedTotals($account->ledger_account_code);
        $ledgerAccount = LedgerAccount::where('code', $account->ledger_account_code)->first();

        if ($ledgerAccount !== null && $ledgerAccount->isDebitNormal()) {
            return DecimalMath::sub($totals['debit'], $totals['credit'], 4);
        }

        return DecimalMath::sub($totals['credit'], $totals['debit'], 4);
    }

    /**
     * Posted totals for any ledger account code.
     *
     * @return array{debit: string, credit: string} Decimal strings, scale 4.
     */
    public function postedTotals(string $accountCode): array
    {
        $lines = JournalLine::where('account_code', $accountCode)
            ->whereHas('entry', fn ($q) => $q->whereIn('status', [EntryStatus::POSTED->value, EntryStatus::REVERSED->value]))
            ->get(['debit_amount', 'credit_amount']);

        $debit = '0';
        $credit = '0';
        foreach ($lines as $line) {
            $debit = DecimalMath::add($debit, (string) $line->debit_amount, 4);
            $credit = DecimalMath::add($credit, (string) $line->credit_amount, 4);
        }

        return ['debit' => $debit, 'credit' => $credit];
    }
}
