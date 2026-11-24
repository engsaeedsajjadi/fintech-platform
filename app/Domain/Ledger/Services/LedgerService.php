<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Contracts\LedgerDriverInterface;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Enums\EntryStatus;
use App\Domain\Ledger\Exceptions\OptimisticConcurrencyException;
use App\Domain\Ledger\Models\IdempotencyKey;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerDriverSync;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Domain\Ledger\ValueObjects\LedgerPrecision;
use App\Support\DecimalString;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LedgerService
{
    public function __construct(
        private readonly LedgerDriverInterface $driver,
    ) {
    }

    private function scale(): int
    {
        return (int) config('ledger.posting_scale', 4);
    }

    /**
     * Create and post a balanced journal entry.
     *
     * This is the ONLY write path for journal entries. Amounts arrive as
     * decimal strings (or ints / JSON numbers normalised at the boundary)
     * and are re-normalised here with DecimalString so no float arithmetic
     * ever touches money: exponent-form values ('1e3', 5.0E-5) are parsed
     * exactly, non-finite values are rejected.
     *
     * Validation (Phase 4.2-B/F): every line's account must exist in the
     * chart when `ledger.require_registered_accounts` is on (an existing
     * account must always be active), all lines share ONE currency (a line
     * without an explicit currency inherits its account's currency), and
     * crypto amounts needing more decimals than the posting scale are
     * refused instead of truncated. Trading virtual accounts
     * (`trading:account:…`) are not chart rows, so the existence check
     * stays opt-in until those accounts are registered.
     *
     * Driver sync (Phase 4.2-E): the DB journal is committed first and is
     * the source of truth. The driver sync runs after the commit; a sync
     * failure is recorded durably in the driver-sync outbox for retry and
     * does NOT fail the posting.
     *
     * @param array<int, array{account_code: string, debit: mixed, credit: mixed, currency?: string, narrative?: string|null}> $lines
     */
    public function post(
        string $description,
        array $lines,
        ?string $sourceDomain = null,
        ?string $sourceEventId = null,
        ?string $idempotencyKey = null,
    ): JournalEntry {
        return $this->postInternal($description, $lines, $sourceDomain, $sourceEventId, $idempotencyKey, null);
    }

    /**
     * @param array<int, array{account_code: string, debit: mixed, credit: mixed, currency?: string, narrative?: string|null}> $lines
     */
    private function postInternal(
        string $description,
        array $lines,
        ?string $sourceDomain,
        ?string $sourceEventId,
        ?string $idempotencyKey,
        ?IdempotencyKey $claim,
    ): JournalEntry {
        $scale = $this->scale();
        $normalised = $this->normaliseLines($lines, $scale);

        // Validate double-entry invariant with exact decimal arithmetic.
        /** @var numeric-string $totalDebit */
        $totalDebit = '0';
        /** @var numeric-string $totalCredit */
        $totalCredit = '0';
        foreach ($normalised as $line) {
            $totalDebit = DecimalMath::add($totalDebit, $line['debit'], $scale);
            $totalCredit = DecimalMath::add($totalCredit, $line['credit'], $scale);
        }

        if (DecimalMath::cmp($totalDebit, $totalCredit, $scale) !== 0) {
            throw new RuntimeException(
                "Journal entry is unbalanced: debits={$totalDebit}, credits={$totalCredit}"
            );
        }

        if (DecimalMath::cmp($totalDebit, '0', $scale) === 0) {
            throw new RuntimeException('Journal entry has zero total — at least one line must have a non-zero amount');
        }

        $entry = $this->persistEntry($description, $normalised, $sourceDomain, $sourceEventId, $idempotencyKey, $claim);

        $this->syncDriver($entry);

        return $entry;
    }

    /**
     * Persist a balanced entry + lines inside a transaction. The driver
     * sync (e.g. TigerBeetle) always happens AFTER the transaction commits,
     * in post() / postGuarded() / reverse() — never inside it.
     *
     * When $claim is given (postIdempotent), attaching the claim to the
     * entry happens in the SAME transaction, so a crash between the entry
     * commit and the claim attach cannot orphan the key.
     *
     * @param array<int, array{account_code: string, debit: string, credit: string, currency: string, narrative: string|null}> $lines
     */
    private function persistEntry(
        string $description,
        array $lines,
        ?string $sourceDomain,
        ?string $sourceEventId,
        ?string $idempotencyKey = null,
        ?IdempotencyKey $claim = null,
    ): JournalEntry {
        return DB::transaction(function () use ($description, $lines, $sourceDomain, $sourceEventId, $idempotencyKey, $claim): JournalEntry {
            $entry = JournalEntry::create([
                'entry_number'    => 'JE-' . strtoupper(Str::random(8)),
                'description'     => $description,
                'status'          => EntryStatus::POSTED,
                'posted_at'       => Carbon::now(),
                'source_domain'   => $sourceDomain,
                'source_event_id' => $sourceEventId,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_code'     => $line['account_code'],
                    'debit_amount'     => $line['debit'],
                    'credit_amount'    => $line['credit'],
                    'currency'         => $line['currency'],
                    'narrative'        => $line['narrative'] ?? null,
                ]);
            }

            if ($claim !== null) {
                $claim->update([
                    'journal_entry_id' => $entry->id,
                    'result'           => ['entry_id' => $entry->id, 'entry_number' => $entry->entry_number],
                ]);
            }

            // Persist the external-driver outbox in the same transaction as the journal.
            if (! ($this->driver instanceof EloquentDriver)) {
                LedgerDriverSync::firstOrCreate(
                    ['journal_entry_id' => $entry->id],
                    ['driver' => class_basename($this->driver), 'status' => 'pending', 'attempts' => 0],
                );
            }

            $entry->load('lines');

            return $entry;
        });
    }

    /**
     * Idempotent posting: replaying the same key with the SAME payload
     * returns the original entry (and the original result) instead of
     * posting a second entry. The key is UNIQUE in the database, so two
     * concurrent submissions race on the claim insert and the loser waits
     * briefly for the winner's entry and returns it — no second entry is
     * ever posted.
     *
     * Crash recovery (Phase 4.2-C): the claim and the entry attach happen
     * in one transaction, so a crash can only leave a claim with no entry
     * when the process dies before the transaction commits. Such a stale
     * claim (older than `ledger.idempotency_claim_ttl_seconds`) is reclaimed
     * by the next caller instead of blocking the key forever. A replay with
     * a DIFFERENT payload under the same key is a conflict and throws —
     * it is never silently answered with the original entry.
     *
     * @param array<int, array{account_code: string, debit: mixed, credit: mixed, currency?: string, narrative?: string|null}> $lines
     */
    public function postIdempotent(
        string $idempotencyKey,
        string $description,
        array $lines,
        ?string $sourceDomain = null,
        ?string $sourceEventId = null,
    ): JournalEntry {
        if (trim($idempotencyKey) === '') {
            throw new RuntimeException('Idempotency key must not be empty.');
        }

        $scale = $this->scale();
        $normalised = $this->normaliseLines($lines, $scale);
        $requestHash = $this->requestHash($normalised, $sourceDomain, $sourceEventId);

        // Fast path: the key already has an entry attached.
        $existing = IdempotencyKey::where('key', $idempotencyKey)->first();

        if ($existing !== null && $existing->journal_entry_id !== null) {
            $this->assertSameRequest($existing, $requestHash, $idempotencyKey);

            return JournalEntry::with('lines')->findOrFail($existing->journal_entry_id);
        }

        if ($existing !== null && $existing->journal_entry_id === null) {
            if ($this->isStaleClaim($existing)) {
                // The winner crashed before attaching its entry: reclaim.
                $existing->delete();
                $existing = null;
            } else {
                return $this->waitForWinner($idempotencyKey, $requestHash);
            }
        }

        // Claim the key first (unique insert), then post. A replay never
        // reaches post(), so no duplicate entry can be created.
        try {
            $claim = IdempotencyKey::create([
                'key'              => $idempotencyKey,
                'domain'           => $sourceDomain ?? 'ledger',
                'journal_entry_id' => null,
                'request_hash'     => $requestHash,
                'result'           => null,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Lost the claim race: wait briefly for the winner to attach
            // its entry, then return it.
            return $this->waitForWinner($idempotencyKey, $requestHash, $e);
        }

        try {
            return $this->postInternal($description, $lines, $sourceDomain, $sourceEventId, $idempotencyKey, $claim);
        } catch (Throwable $e) {
            // Posting failed: release the claim so the key is not poisoned
            // for a legitimate retry. (The entry itself is created and the
            // claim attached in one transaction, so a successful post can
            // never land here with an orphaned entry.)
            $claim->delete();

            throw $e;
        }
    }

    /**
     * Post a balanced entry that must not overdraw a debit-normal ledger
     * account, with row locking and optimistic concurrency.
     *
     * Inside one transaction the affected ledger-account rows are locked
     * (SELECT … FOR UPDATE), the post-entry balance of every debit-normal
     * account touched by a credit line is computed exactly, and the posting
     * is aborted when any such balance would go negative. Each touched
     * account's `version` is bumped only when it still equals the version
     * read under the lock, so a concurrent writer is detected instead of
     * silently overwritten.
     *
     * The balance check reads the DB journal directly (Phase 4.2-E): the
     * previous implementation called `$this->driver->balance()` here, which
     * for an external driver is a NETWORK call inside a long DB transaction
     * — and could return a fabricated zero on outage, defeating the guard.
     *
     * @param array<int, array{account_code: string, debit: mixed, credit: mixed, currency?: string, narrative?: string|null}> $lines
     * @param list<string> $guardAccountCodes Debit-normal account codes that must never go negative.
     */
    public function postGuarded(
        string $description,
        array $lines,
        array $guardAccountCodes,
        ?string $sourceDomain = null,
        ?string $sourceEventId = null,
        ?string $idempotencyKey = null,
    ): JournalEntry {
        $scale = $this->scale();
        $normalised = $this->normaliseLines($lines, $scale);
        $claim = null;

        if ($idempotencyKey !== null) {
            if (trim($idempotencyKey) === '') {
                throw new RuntimeException('Idempotency key must not be empty.');
            }
            $requestHash = $this->requestHash($normalised, $sourceDomain, $sourceEventId);
            $existing = IdempotencyKey::where('key', $idempotencyKey)->first();
            if ($existing !== null && $existing->journal_entry_id !== null) {
                $this->assertSameRequest($existing, $requestHash, $idempotencyKey);
                return JournalEntry::with('lines')->findOrFail($existing->journal_entry_id);
            }
            if ($existing !== null && $existing->journal_entry_id === null) {
                if ($this->isStaleClaim($existing)) {
                    $existing->delete();
                } else {
                    return $this->waitForWinner($idempotencyKey, $requestHash);
                }
            }
            try {
                $claim = IdempotencyKey::create([
                    'key' => $idempotencyKey, 'domain' => $sourceDomain ?? 'ledger',
                    'journal_entry_id' => null, 'request_hash' => $requestHash, 'result' => null,
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
                return $this->waitForWinner($idempotencyKey, $requestHash, $e);
            }
        }

        try {
            $posted = null;
            DB::transaction(function () use ($description, $normalised, $guardAccountCodes, $sourceDomain, $sourceEventId, $idempotencyKey, $claim, $scale, &$posted): void {
                $versions = [];
                $currencies = [];
                foreach (array_values(array_unique($guardAccountCodes)) as $code) {
                    $account = LedgerAccount::where('code', $code)->lockForUpdate()->first();
                    if ($account === null) {
                        throw new RuntimeException("Guarded ledger account '{$code}' does not exist.");
                    }
                    $versions[$code] = (int) $account->version;
                    $currencies[$code] = (string) $account->currency;
                }

                $posted = $this->persistEntry($description, $normalised, $sourceDomain, $sourceEventId, $idempotencyKey, $claim);
                foreach (array_values(array_unique($guardAccountCodes)) as $code) {
                    $balance = $this->dbBalance($code, $currencies[$code], $scale);
                    if (DecimalMath::cmp($balance, '0', $scale) < 0) {
                        throw new RuntimeException("Posting would overdraw ledger account '{$code}': resulting balance {$balance}.");
                    }
                    $updated = LedgerAccount::where('code', $code)->where('version', $versions[$code])
                        ->update(['version' => $versions[$code] + 1]);
                    if ($updated !== 1) {
                        throw new OptimisticConcurrencyException("Ledger account '{$code}' was modified concurrently.");
                    }
                }
            });
        } catch (Throwable $e) {
            if ($claim !== null) {
                $claim->delete();
            }
            throw $e;
        }

        $this->syncDriver($posted);
        return $posted;
    }
    /**
     * Reverse a posted journal entry by creating a mirror entry.
     *
     * The original entry is never edited in place: the reversal is a new
     * balanced entry with debits/credits swapped, and the original is
     * marked REVERSED with a link to it. The status flip happens under a
     * row lock and re-checks that the entry is still POSTED, so two
     * concurrent reversals cannot both succeed.
     */
    public function reverse(string $entryId, string $reason): JournalEntry
    {
        $scale = $this->scale();

        // The lock, the mirror entry and the status flip happen in ONE
        // transaction, so two concurrent reversals cannot both succeed and
        // no orphan mirror entry can be created. The driver sync runs
        // after the transaction commits.
        $reversal = null;
        DB::transaction(function () use ($entryId, $reason, $scale, &$reversal): void {
            $original = JournalEntry::with('lines')->lockForUpdate()->findOrFail($entryId);

            if ($original->status !== EntryStatus::POSTED) {
                throw new RuntimeException("Cannot reverse entry with status: {$original->status->value}");
            }

            // Create reversal lines (swap debits and credits)
            /** @var array<int, array{account_code: string, debit: string, credit: string, currency: string, narrative: string|null}> $reversalLines */
            $reversalLines = [];
            foreach ($original->lines as $line) {
                /** @var numeric-string $creditAmt */
                $creditAmt = (string) $line->credit_amount;
                /** @var numeric-string $debitAmt */
                $debitAmt = (string) $line->debit_amount;
                $reversalLines[] = [
                    'account_code' => $line->account_code,
                    'debit'        => DecimalMath::add($creditAmt, '0', $scale),
                    'credit'       => DecimalMath::add($debitAmt, '0', $scale),
                    'currency'     => $line->currency,
                    'narrative'    => "Reversal: {$reason}",
                ];
            }

            $reversal = $this->persistEntry(
                "Reversal of {$original->entry_number}: {$reason}",
                $reversalLines,
                $original->source_domain,
                $original->source_event_id,
            );

            $original->update([
                'status'      => EntryStatus::REVERSED,
                'reversed_by' => $reversal->id,
            ]);
        });

        /** @var JournalEntry $reversal */
        $this->syncDriver($reversal);

        return $reversal;
    }

    /**
     * Get the balance for a specific account.
     *
     * @return array{amount: string, currency: string}
     */
    public function getBalance(string $accountCode, ?Carbon $asOf = null): array
    {
        return $this->driver->balance($accountCode, $asOf);
    }

    /**
     * Get the full trial balance.
     *
     * @return array<string, array{debit: string, credit: string, balance: string}>
     */
    public function getTrialBalance(?Carbon $asOf = null): array
    {
        return $this->driver->trialBalance($asOf);
    }

    /**
     * Get account transaction history.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getAccountHistory(string $accountCode, Carbon $from, Carbon $to): Collection
    {
        return $this->driver->accountHistory($accountCode, $from, $to);
    }

    /**
     * Sync a committed entry to the external driver (outbox pattern).
     *
     * The DB journal is authoritative: a driver failure NEVER fails the
     * posting and NEVER leaves the failure untracked. It is recorded in
     * `ledger_driver_syncs` as pending (with attempt count + error) for
     * `ledger:retry-driver-sync`, and a later successful sync clears the
     * pending row. The Eloquent driver is a no-op, so no rows are created
     * for it.
     */
    private function syncDriver(JournalEntry $entry): void
    {
        if ($this->driver instanceof EloquentDriver) {
            return;
        }
        $sync = function () use ($entry): void {
            $row = LedgerDriverSync::where('journal_entry_id', $entry->id)->first();
            if ($row === null || $row->status === 'synced') {
                return;
            }
            try {
                $this->driver->post($entry);
                $row->update(['status' => 'synced', 'synced_at' => Carbon::now(), 'last_error' => null]);
            } catch (Throwable $e) {
                Log::error('Ledger driver sync failed — retained in transactional outbox', [
                    'entry_id' => $entry->id, 'driver' => $this->driver::class, 'error' => $e->getMessage(),
                ]);
                $row->update([
                    'status' => 'pending', 'attempts' => (int) $row->attempts + 1,
                    'last_error' => mb_substr($e->getMessage(), 0, 2000), 'synced_at' => null,
                ]);
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($sync);
            return;
        }
        $sync();
    }
    /**
     * Balance of one account in one currency, straight from the DB journal
     * (posted + reversed entries; the mirror nets the original out, so
     * reversed entries must be included — see EloquentDriver::balance()).
     * Used by postGuarded() inside its transaction: no driver call, no
     * network, no fabricated zero.
     */
    private function dbBalance(string $accountCode, string $currency, int $scale): string
    {
        $totals = JournalLine::where('account_code', $accountCode)
            ->where('currency', $currency)
            ->whereHas('entry', fn ($q) => $q->whereIn('status', [EntryStatus::POSTED->value, EntryStatus::REVERSED->value]))
            ->selectRaw('COALESCE(SUM(debit_amount), 0) as total_debit, COALESCE(SUM(credit_amount), 0) as total_credit')
            ->first();

        /** @var numeric-string $rawDebit */
        $rawDebit = (string) ($totals->total_debit ?? '0');
        /** @var numeric-string $rawCredit */
        $rawCredit = (string) ($totals->total_credit ?? '0');
        $totalDebit = DecimalMath::add($rawDebit, '0', $scale);
        $totalCredit = DecimalMath::add($rawCredit, '0', $scale);

        $account = LedgerAccount::where('code', $accountCode)->first();

        if ($account !== null && $account->isDebitNormal()) {
            return DecimalMath::sub($totalDebit, $totalCredit, $scale);
        }

        return DecimalMath::sub($totalCredit, $totalDebit, $scale);
    }

    /**
     * Fingerprint of an idempotent request: normalised lines + source.
     * A replay must reproduce this hash exactly, or it is a conflict.
     *
     * @param array<int, array{account_code: string, debit: string, credit: string, currency: string, narrative: string|null}> $normalised
     */
    private function requestHash(array $normalised, ?string $sourceDomain, ?string $sourceEventId): string
    {
        return hash('sha256', (string) json_encode([$normalised, $sourceDomain, $sourceEventId], JSON_THROW_ON_ERROR));
    }

    private function assertSameRequest(IdempotencyKey $existing, string $requestHash, string $idempotencyKey): void
    {
        // Legacy rows (created before the fingerprint column) carry no hash
        // and keep the old replay behaviour; every new claim is checked.
        if ($existing->request_hash !== null && $existing->request_hash !== $requestHash) {
            throw new RuntimeException(
                "Idempotency key '{$idempotencyKey}' was already used with a different payload; refusing to return the original entry."
            );
        }
    }

    private function isStaleClaim(IdempotencyKey $claim): bool
    {
        $ttl = (int) config('ledger.idempotency_claim_ttl_seconds', 120);
        $updatedAt = $claim->updated_at;

        return $updatedAt === null || $updatedAt->lt(Carbon::now()->subSeconds($ttl));
    }

    private function waitForWinner(string $idempotencyKey, string $requestHash, ?Throwable $previous = null): JournalEntry
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            usleep(100_000);
            $winner = IdempotencyKey::where('key', $idempotencyKey)->first();

            if ($winner !== null && $winner->journal_entry_id !== null) {
                $this->assertSameRequest($winner, $requestHash, $idempotencyKey);

                return JournalEntry::with('lines')->findOrFail($winner->journal_entry_id);
            }
        }

        throw new RuntimeException("Duplicate idempotency key '{$idempotencyKey}' is still being processed.", 0, $previous);
    }

    /**
     * Normalise raw line amounts to exact decimal strings at the posting
     * scale and validate shape. No float arithmetic is performed anywhere:
     * DecimalString parses floats/exponent strings exactly and rejects
     * non-finite values.
     *
     * Also enforces, per line: the account exists in the chart when
     * `ledger.require_registered_accounts` is on (an existing account must
     * always be active), one currency per entry (a missing line currency
     * is inherited from its account, so a EUR transfer can no longer be
     * silently labelled USD), and the precision model (crypto amounts that
     * need more decimals than the posting scale are refused, not truncated).
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array{account_code: string, debit: string, credit: string, currency: string, narrative: string|null}>
     */
    private function normaliseLines(array $lines, int $scale): array
    {
        if (count($lines) < 2) {
            throw new RuntimeException('A journal entry needs at least two lines.');
        }

        $codes = [];
        foreach ($lines as $line) {
            $code = (string) ($line['account_code'] ?? '');

            if ($code === '') {
                throw new RuntimeException('Every journal line needs an account_code.');
            }

            $codes[] = $code;
        }

        /** @var array<string, LedgerAccount> $accounts */
        $accounts = LedgerAccount::whereIn('code', array_values(array_unique($codes)))->get()->keyBy('code')->all();
        $requireRegistered = (bool) config('ledger.require_registered_accounts', false);
        $defaultCurrency = strtoupper((string) config('ledger.default_currency', 'USD'));

        $normalised = [];
        foreach ($lines as $line) {
            $accountCode = (string) ($line['account_code'] ?? '');
            $account = $accounts[$accountCode] ?? null;

            if ($account === null && $requireRegistered) {
                throw new RuntimeException(
                    "Ledger account '{$accountCode}' is not registered in the chart of accounts."
                );
            }

            if ($account !== null && ! $account->is_active) {
                throw new RuntimeException("Ledger account '{$accountCode}' is inactive.");
            }

            $rawCurrency = $line['currency'] ?? null;
            $explicitCurrency = is_string($rawCurrency) && $rawCurrency !== ''
                ? strtoupper($rawCurrency)
                : null;

            if ($explicitCurrency !== null && ! preg_match('/^[A-Z0-9]{2,10}$/', $explicitCurrency)) {
                throw new RuntimeException("Invalid currency code '{$explicitCurrency}'.");
            }

            $currency = $explicitCurrency ?? ($account !== null ? strtoupper($account->currency) : $defaultCurrency);

            if ($explicitCurrency !== null && $account !== null && strtoupper($account->currency) !== $explicitCurrency) {
                throw new RuntimeException(
                    "Journal line currency '{$explicitCurrency}' does not match ledger account '{$accountCode}' currency '{$account->currency}'."
                );
            }

            $debit = DecimalString::fromNumeric($line['debit'] ?? null, $scale);
            $credit = DecimalString::fromNumeric($line['credit'] ?? null, $scale);

            if ($debit === null || $credit === null) {
                throw new RuntimeException('Each debit and credit must be a finite decimal number.');
            }

            if (DecimalMath::cmp($debit, '0', $scale) < 0 || DecimalMath::cmp($credit, '0', $scale) < 0) {
                throw new RuntimeException('Journal line amounts must not be negative.');
            }

            // Fail-closed precision: a BTC amount like 0.00000001 must never
            // be silently truncated to 0.0000.
            LedgerPrecision::assertRepresentable($line['debit'] ?? null, $currency, $scale);
            LedgerPrecision::assertRepresentable($line['credit'] ?? null, $currency, $scale);

            $normalised[] = [
                'account_code' => $accountCode,
                'debit'        => $debit,
                'credit'       => $credit,
                'currency'     => $currency,
                'narrative'    => isset($line['narrative']) ? (string) $line['narrative'] : null,
            ];
        }

        $currencies = array_values(array_unique(array_column($normalised, 'currency')));

        if (count($currencies) > 1) {
            throw new RuntimeException(
                'Journal entry mixes currencies (' . implode(', ', $currencies) . ') — one entry, one currency.'
            );
        }

        return $normalised;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        // SQLite: 19 / 23000; MySQL: 23000 / 1062; Postgres: 23505.
        return in_array($code, ['19', '23000', '23505'], true)
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
