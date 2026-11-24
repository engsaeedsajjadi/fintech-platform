<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\InvalidAssetException;
use App\Domain\Wallet\Models\FiatDeposit;
use App\Domain\Wallet\Models\FiatTransfer;
use App\Domain\Wallet\Models\FiatWithdrawal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fiat wallet: account management, deposits, withdrawals (with limits +
 * approval workflow), internal transfers, history and reconciliation.
 *
 * Balances are NEVER stored on a wallet field. The balance of a fiat
 * wallet is the balance of its ledger account
 * `WALLET:FIAT:{account_uuid}:{CURRENCY}` (liability, credit-normal),
 * read via LedgerService::getBalance(). Every balance change is a
 * balanced LedgerService::post() entry; the FiatDeposit / FiatWithdrawal /
 * FiatTransfer rows are intent/audit records that reference the entry.
 *
 * Amounts are decimal strings in MAJOR units (e.g. '100.50'); the ledger
 * stores them at scale 4. No float is used for money anywhere in this
 * service. A deposit booked here represents funds already received via a
 * provider/custodian (provider_reference is required); the external
 * funding leg is the provider's job, not this service's.
 */
final class FiatWalletService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly WalletLimitsService $limits,
    ) {
    }

    public static function walletAccountCode(string $accountUuid, string $currency): string
    {
        return "WALLET:FIAT:{$accountUuid}:{$currency}";
    }

    public static function clearingAccountCode(string $currency): string
    {
        return "WALLET:CLEARING:{$currency}";
    }

    public static function feesAccountCode(string $currency): string
    {
        return "WALLET:FEES:{$currency}";
    }

    // ------------------------------------------------------------------
    // Accounts / balances
    // ------------------------------------------------------------------

    /** Ensure the ledger accounts for a fiat wallet exist (chart setup, not a balance write). */
    public function ensureWalletAccounts(string $accountUuid, string $currency): void
    {
        $currency = $this->validateCurrency($currency);
        $this->ensureLedgerAccount(self::walletAccountCode($accountUuid, $currency), "Fiat wallet {$accountUuid} {$currency}", AccountType::LIABILITY, $currency);
        $this->ensureLedgerAccount(self::clearingAccountCode($currency), "Fiat clearing {$currency}", AccountType::ASSET, $currency);
        $this->ensureLedgerAccount(self::feesAccountCode($currency), "Fiat wallet fees {$currency}", AccountType::REVENUE, $currency);
    }

    /** Balance from the ledger (major units, decimal string). */
    public function balance(string $accountUuid, string $currency): string
    {
        $currency = $this->validateCurrency($currency);

        return DecimalMath::scale4($this->ledger->getBalance(self::walletAccountCode($accountUuid, $currency))['amount']);
    }

    /** Balances for every configured fiat currency. @return array<string, string> */
    public function balances(string $accountUuid): array
    {
        $out = [];
        foreach ((array) config('wallet.fiat.currencies', ['USD']) as $currency) {
            $out[$currency] = $this->balance($accountUuid, (string) $currency);
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Deposits
    // ------------------------------------------------------------------

    /**
     * Book a confirmed deposit: funds already received via $providerReference.
     * Idempotent on $idempotencyKey — a replay returns the original deposit
     * without posting a second journal entry.
     */
    public function deposit(
        string $accountUuid,
        string $currency,
        string $amount,
        string $idempotencyKey,
        string $providerReference,
        ?string $requestedBy = null,
    ): FiatDeposit {
        $currency = $this->validateCurrency($currency);
        DecimalMath::assertPositiveDecimal($amount);

        return DB::transaction(function () use ($accountUuid, $currency, $amount, $idempotencyKey, $providerReference, $requestedBy): FiatDeposit {
            $existing = FiatDeposit::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $this->limits->checkFiatDeposit($accountUuid, $currency, $amount);
            $this->ensureWalletAccounts($accountUuid, $currency);

            $fee = $this->fee($amount);
            $net = DecimalMath::sub($amount, $fee);
            $amount4 = DecimalMath::scale4($amount);

            $entry = $this->postDeposit($accountUuid, $currency, $amount4, $fee, $net, $idempotencyKey);

            return FiatDeposit::query()->create([
                'uuid'               => (string) Str::uuid(),
                'account_uuid'       => $accountUuid,
                'currency'           => $currency,
                'amount'             => $amount4,
                'fee'                => $fee,
                'net_amount'         => $net,
                'status'             => FiatDeposit::STATUS_COMPLETED,
                'idempotency_key'    => $idempotencyKey,
                'provider_reference' => $providerReference,
                'journal_entry_id'   => $entry->id,
                'requested_by'       => $requestedBy,
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Withdrawals (request -> approve/reject -> execute)
    // ------------------------------------------------------------------

    public function requestWithdrawal(
        string $accountUuid,
        string $currency,
        string $amount,
        string $idempotencyKey,
        ?string $destinationReference = null,
        ?string $requestedBy = null,
    ): FiatWithdrawal {
        $currency = $this->validateCurrency($currency);
        DecimalMath::assertPositiveDecimal($amount);

        return DB::transaction(function () use ($accountUuid, $currency, $amount, $idempotencyKey, $destinationReference, $requestedBy): FiatWithdrawal {
            $existing = FiatWithdrawal::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $this->limits->checkFiatWithdrawal($accountUuid, $currency, $amount);
            $this->assertSufficientBalance($accountUuid, $currency, $amount);

            $fee = $this->fee($amount);
            $net = DecimalMath::sub($amount, $fee);
            $autoApproveUpTo = (string) config('wallet.fiat.auto_approve_withdrawal_up_to', '0');

            return FiatWithdrawal::query()->create([
                'uuid'         => (string) Str::uuid(),
                'account_uuid' => $accountUuid,
                'currency'     => $currency,
                'amount'       => DecimalMath::scale4($amount),
                'fee'          => $fee,
                'net_amount'   => $net,
                // Small withdrawals may auto-approve; everything else waits
                // for a second person. Execution is a separate step either way.
                'status' => DecimalMath::cmp($amount, $autoApproveUpTo) <= 0 && DecimalMath::isPositive($autoApproveUpTo)
                    ? FiatWithdrawal::STATUS_APPROVED
                    : FiatWithdrawal::STATUS_PENDING_APPROVAL,
                'idempotency_key'       => $idempotencyKey,
                'destination_reference' => $destinationReference,
                'requested_by'          => $requestedBy,
                'requested_at'          => Carbon::now(),
            ]);
        });
    }

    /** Four-eyes approval: the approver must differ from the requester. */
    public function approveWithdrawal(string $withdrawalUuid, string $approver, ?string $note = null): FiatWithdrawal
    {
        return DB::transaction(function () use ($withdrawalUuid, $approver, $note): FiatWithdrawal {
            $withdrawal = $this->lockWithdrawal($withdrawalUuid);
            if ($withdrawal->status !== FiatWithdrawal::STATUS_PENDING_APPROVAL) {
                throw new InvalidArgumentException("Withdrawal {$withdrawalUuid} is {$withdrawal->status} and cannot be approved.");
            }
            if ($withdrawal->requested_by !== null && $withdrawal->requested_by === $approver) {
                throw new InvalidArgumentException('Four-eyes rule: the approver must differ from the requester.');
            }
            $withdrawal->update([
                'status'      => FiatWithdrawal::STATUS_APPROVED,
                'reviewed_by' => $approver,
                'review_note' => $note,
                'reviewed_at' => Carbon::now(),
            ]);

            return $withdrawal->refresh();
        });
    }

    public function rejectWithdrawal(string $withdrawalUuid, string $reviewer, ?string $note = null): FiatWithdrawal
    {
        return DB::transaction(function () use ($withdrawalUuid, $reviewer, $note): FiatWithdrawal {
            $withdrawal = $this->lockWithdrawal($withdrawalUuid);
            if ($withdrawal->status !== FiatWithdrawal::STATUS_PENDING_APPROVAL) {
                throw new InvalidArgumentException("Withdrawal {$withdrawalUuid} is {$withdrawal->status} and cannot be rejected.");
            }
            $withdrawal->update([
                'status'      => FiatWithdrawal::STATUS_REJECTED,
                'reviewed_by' => $reviewer,
                'review_note' => $note,
                'reviewed_at' => Carbon::now(),
            ]);

            return $withdrawal->refresh();
        });
    }

    /**
     * Execute an approved withdrawal: the only place funds move. Re-checks
     * the ledger balance at execution time. Idempotent by status: an
     * already-completed withdrawal returns as-is, never posts twice.
     */
    public function executeWithdrawal(string $withdrawalUuid): FiatWithdrawal
    {
        return DB::transaction(function () use ($withdrawalUuid): FiatWithdrawal {
            $withdrawal = $this->lockWithdrawal($withdrawalUuid);
            if ($withdrawal->status === FiatWithdrawal::STATUS_COMPLETED) {
                return $withdrawal;
            }
            if ($withdrawal->status !== FiatWithdrawal::STATUS_APPROVED) {
                throw new InvalidArgumentException("Withdrawal {$withdrawalUuid} is {$withdrawal->status}; only an approved withdrawal can execute.");
            }

            $currency = $withdrawal->currency;
            $amount = DecimalMath::scale4((string) $withdrawal->amount);
            $fee = DecimalMath::scale4((string) $withdrawal->fee);
            $net = DecimalMath::scale4((string) $withdrawal->net_amount);
            $this->ensureWalletAccounts($withdrawal->account_uuid, $currency);
            $this->assertSufficientBalance($withdrawal->account_uuid, $currency, $amount);

            $lines = [
                ['account_code' => self::walletAccountCode($withdrawal->account_uuid, $currency), 'debit' => $amount, 'credit' => '0.0000', 'currency' => $currency, 'narrative' => "Withdrawal {$withdrawal->uuid}"],
                ['account_code' => self::clearingAccountCode($currency), 'debit' => '0.0000', 'credit' => $net, 'currency' => $currency, 'narrative' => "Withdrawal {$withdrawal->uuid} payout"],
            ];
            if (DecimalMath::cmp($fee, '0') > 0) {
                $lines[] = ['account_code' => self::feesAccountCode($currency), 'debit' => '0.0000', 'credit' => $fee, 'currency' => $currency, 'narrative' => "Withdrawal {$withdrawal->uuid} fee"];
            }
            $entry = $this->ledger->post("Fiat withdrawal {$withdrawal->uuid}", $lines, 'Wallet', $withdrawal->uuid);

            $withdrawal->update([
                'status'           => FiatWithdrawal::STATUS_COMPLETED,
                'journal_entry_id' => $entry->id,
                'executed_at'      => Carbon::now(),
            ]);

            return $withdrawal->refresh();
        });
    }

    /** Admin review hook: withdrawals waiting for a decision. */
    public function pendingWithdrawals(): Collection
    {
        return FiatWithdrawal::query()->where('status', FiatWithdrawal::STATUS_PENDING_APPROVAL)->orderBy('created_at')->get();
    }

    // ------------------------------------------------------------------
    // Internal transfers
    // ------------------------------------------------------------------

    /** Internal transfer between two fiat wallets. Idempotent on $idempotencyKey. */
    public function transfer(
        string $fromAccountUuid,
        string $toAccountUuid,
        string $currency,
        string $amount,
        string $idempotencyKey,
        ?string $requestedBy = null,
    ): FiatTransfer {
        $currency = $this->validateCurrency($currency);
        DecimalMath::assertPositiveDecimal($amount);
        if ($fromAccountUuid === $toAccountUuid) {
            throw new InvalidArgumentException('Internal transfer requires two different accounts.');
        }

        return DB::transaction(function () use ($fromAccountUuid, $toAccountUuid, $currency, $amount, $idempotencyKey, $requestedBy): FiatTransfer {
            $existing = FiatTransfer::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $this->limits->checkFiatTransfer($fromAccountUuid, $currency, $amount);
            $this->ensureWalletAccounts($fromAccountUuid, $currency);
            $this->ensureWalletAccounts($toAccountUuid, $currency);
            $this->assertSufficientBalance($fromAccountUuid, $currency, $amount);

            $fee = $this->fee($amount);
            $net = DecimalMath::sub($amount, $fee);
            $amount4 = DecimalMath::scale4($amount);

            $lines = [
                ['account_code' => self::walletAccountCode($fromAccountUuid, $currency), 'debit' => $amount4, 'credit' => '0.0000', 'currency' => $currency, 'narrative' => "Internal transfer to {$toAccountUuid}"],
                ['account_code' => self::walletAccountCode($toAccountUuid, $currency), 'debit' => '0.0000', 'credit' => $net, 'currency' => $currency, 'narrative' => "Internal transfer from {$fromAccountUuid}"],
            ];
            if (DecimalMath::cmp($fee, '0') > 0) {
                $lines[] = ['account_code' => self::feesAccountCode($currency), 'debit' => '0.0000', 'credit' => $fee, 'currency' => $currency, 'narrative' => 'Internal transfer fee'];
            }
            $entry = $this->ledger->post("Internal fiat transfer {$fromAccountUuid} -> {$toAccountUuid}", $lines, 'Wallet', $idempotencyKey);

            return FiatTransfer::query()->create([
                'uuid'              => (string) Str::uuid(),
                'from_account_uuid' => $fromAccountUuid,
                'to_account_uuid'   => $toAccountUuid,
                'currency'          => $currency,
                'amount'            => $amount4,
                'fee'               => $fee,
                'status'            => FiatTransfer::STATUS_COMPLETED,
                'idempotency_key'   => $idempotencyKey,
                'journal_entry_id'  => $entry->id,
                'requested_by'      => $requestedBy,
            ]);
        });
    }

    // ------------------------------------------------------------------
    // History + reconciliation
    // ------------------------------------------------------------------

    /** Ledger transaction history for one fiat wallet (authoritative). */
    public function history(string $accountUuid, string $currency, Carbon $from, Carbon $to): Collection
    {
        $currency = $this->validateCurrency($currency);

        return $this->ledger->getAccountHistory(self::walletAccountCode($accountUuid, $currency), $from, $to);
    }

    /** Intent history (deposits + withdrawals + transfers) for admin review. */
    public function intentHistory(string $accountUuid, string $currency, int $limit = 100): array
    {
        $deposits = FiatDeposit::query()->where('account_uuid', $accountUuid)->where('currency', $currency)
            ->latest()->limit($limit)->get()->map(fn (FiatDeposit $d): array => ['type' => 'deposit', 'uuid' => $d->uuid, 'amount' => (string) $d->amount, 'fee' => (string) $d->fee, 'status' => $d->status, 'at' => $d->created_at?->toIso8601String()]);
        $withdrawals = FiatWithdrawal::query()->where('account_uuid', $accountUuid)->where('currency', $currency)
            ->latest()->limit($limit)->get()->map(fn (FiatWithdrawal $w): array => ['type' => 'withdrawal', 'uuid' => $w->uuid, 'amount' => (string) $w->amount, 'fee' => (string) $w->fee, 'status' => $w->status, 'at' => $w->created_at?->toIso8601String()]);
        $out = FiatTransfer::query()->where('from_account_uuid', $accountUuid)->where('currency', $currency)
            ->latest()->limit($limit)->get()->map(fn (FiatTransfer $t): array => ['type' => 'transfer_out', 'uuid' => $t->uuid, 'amount' => (string) $t->amount, 'fee' => (string) $t->fee, 'status' => $t->status, 'at' => $t->created_at?->toIso8601String()]);
        $in = FiatTransfer::query()->where('to_account_uuid', $accountUuid)->where('currency', $currency)
            ->latest()->limit($limit)->get()->map(fn (FiatTransfer $t): array => ['type' => 'transfer_in', 'uuid' => $t->uuid, 'amount' => (string) $t->amount, 'fee' => (string) $t->fee, 'status' => $t->status, 'at' => $t->created_at?->toIso8601String()]);

        return $deposits->merge($withdrawals)->merge($out)->merge($in)
            ->sortByDesc('at')->values()->take($limit)->all();
    }

    /**
     * Reconciliation hook: ledger balance vs the balance implied by the
     * intent tables. A mismatch is reported as requires-manual-review and
     * is NEVER auto-corrected here.
     *
     * @return array{account_uuid: string, currency: string, ledger_balance: string, expected_balance: string, difference: string, matched: bool, status: string}
     */
    public function reconcile(string $accountUuid, string $currency): array
    {
        $currency = $this->validateCurrency($currency);
        $ledgerBalance = $this->balance($accountUuid, $currency);

        $depositsNet = (string) FiatDeposit::query()->where('account_uuid', $accountUuid)->where('currency', $currency)
            ->where('status', FiatDeposit::STATUS_COMPLETED)->sum('net_amount');
        $withdrawals = (string) FiatWithdrawal::query()->where('account_uuid', $accountUuid)->where('currency', $currency)
            ->where('status', FiatWithdrawal::STATUS_COMPLETED)->sum('amount');
        $out = FiatTransfer::query()->where('from_account_uuid', $accountUuid)->where('currency', $currency)
            ->where('status', FiatTransfer::STATUS_COMPLETED)->get();
        $in = FiatTransfer::query()->where('to_account_uuid', $accountUuid)->where('currency', $currency)
            ->where('status', FiatTransfer::STATUS_COMPLETED)->get();

        $expected = DecimalMath::scale4($depositsNet);
        $expected = DecimalMath::sub($expected, DecimalMath::scale4($withdrawals));
        foreach ($out as $t) {
            $expected = DecimalMath::sub($expected, DecimalMath::scale4((string) $t->amount));
        }
        foreach ($in as $t) {
            $expected = DecimalMath::add($expected, DecimalMath::sub(DecimalMath::scale4((string) $t->amount), DecimalMath::scale4((string) $t->fee)));
        }

        $difference = DecimalMath::sub($ledgerBalance, $expected);

        return [
            'account_uuid'     => $accountUuid,
            'currency'         => $currency,
            'ledger_balance'   => $ledgerBalance,
            'expected_balance' => $expected,
            'difference'       => $difference,
            'matched'          => DecimalMath::cmp($difference, '0') === 0,
            'status'           => DecimalMath::cmp($difference, '0') === 0 ? 'matched' : 'requires-manual-review',
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function postDeposit(string $accountUuid, string $currency, string $amount4, string $fee, string $net, string $idempotencyKey): JournalEntry
    {
        $lines = [
            ['account_code' => self::clearingAccountCode($currency), 'debit' => $amount4, 'credit' => '0.0000', 'currency' => $currency, 'narrative' => 'Fiat deposit clearing'],
            ['account_code' => self::walletAccountCode($accountUuid, $currency), 'debit' => '0.0000', 'credit' => $net, 'currency' => $currency, 'narrative' => 'Fiat deposit'],
        ];
        if (DecimalMath::cmp($fee, '0') > 0) {
            $lines[] = ['account_code' => self::feesAccountCode($currency), 'debit' => '0.0000', 'credit' => $fee, 'currency' => $currency, 'narrative' => 'Fiat deposit fee'];
        }

        return $this->ledger->post("Fiat deposit {$accountUuid} {$currency} {$amount4}", $lines, 'Wallet', $idempotencyKey);
    }

    /** @return numeric-string */
    private function fee(string $amount): string
    {
        return FeeCalculator::calculate(
            $amount,
            (int) config('wallet.fiat.fee_bps', 0),
            (string) config('wallet.fiat.fee_fixed', '0'),
            (string) config('wallet.fiat.fee_min', '0'),
            (string) config('wallet.fiat.fee_max', '0'),
            4,
        );
    }

    private function validateCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        $allowed = array_map('strval', (array) config('wallet.fiat.currencies', ['USD']));
        if (! in_array($currency, $allowed, true)) {
            throw new InvalidAssetException("Currency {$currency} is not supported for fiat wallets. Supported: " . implode(', ', $allowed));
        }

        return $currency;
    }

    private function assertSufficientBalance(string $accountUuid, string $currency, string $amount): void
    {
        $available = $this->balance($accountUuid, $currency);
        if (DecimalMath::cmp($available, DecimalMath::scale4($amount)) < 0) {
            throw new InsufficientBalanceException($accountUuid, $currency, DecimalMath::scale4($amount), $available);
        }
    }

    private function ensureLedgerAccount(string $code, string $name, AccountType $type, string $currency): void
    {
        LedgerAccount::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'type' => $type->value, 'currency' => $currency, 'is_active' => true],
        );
    }

    private function lockWithdrawal(string $withdrawalUuid): FiatWithdrawal
    {
        return FiatWithdrawal::query()->where('uuid', $withdrawalUuid)->lockForUpdate()->firstOrFail();
    }
}
