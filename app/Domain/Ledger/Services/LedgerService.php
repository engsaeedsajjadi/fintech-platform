<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Contracts\LedgerDriverInterface;
use App\Domain\Ledger\Enums\EntryStatus;
use App\Domain\Ledger\Exceptions\OptimisticConcurrencyException;
use App\Domain\Ledger\Models\IdempotencyKey;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Support\DecimalString;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

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
     * @param array<int, array{account_code: string, debit: mixed, credit: mixed, currency?: string, narrative?: string|null}> $lines
     */
    public function post(
        string $description,
        array $lines,
        ?string $sourceDomain = null,
        ?string $sourceEventId = null,
        ?string $idempotencyKey = null,
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

        $entry = $this->persistEntry($description, $normalised, $sourceDomain, $sourceEventId, $idempotencyKey);

        $this->driver->post($entry);

        return $entry;
    }

    /**
     * Persist a balanced entry + lines inside a transaction. The driver
     * sync (e.g. TigerBeetle) always happens AFTER the transaction commits,
     * in post() / postGuarded() / reverse() — never inside it.
     *
     * @param array<int, array{account_code: string, debit: string, credit: string, currency: string, narrative: string|null}> $lines
     */
    private function persistEntry(
        string $description,
        array $lines,
        ?string $sourceDomain,
        ?string $sourceEventId,
        ?string $idempotencyKey = null,
    ): JournalEntry {
        return DB::transaction(function () use ($description, $lines, $sourceDomain, $sourceEventId, $idempotencyKey): JournalEntry {
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

            $entry->load('lines');

            return $entry;
        });
    }

    /**
     * Idempotent posting: replaying the same key returns the original entry
     * (and the original result) instead of posting a second entry. The key
     * is UNIQUE in the database, so two concurrent submissions race on the
     * claim insert and the loser waits briefly for the winner's entry and
     * returns it — no second entry is ever posted.
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

        // Fast path: the key already has an entry attached.
        $existing = IdempotencyKey::where('key', $idempotencyKey)->first();

        if ($existing !== null && $existing->journal_entry_id !== null) {
            return JournalEntry::with('lines')->findOrFail($existing->journal_entry_id);
        }

        // Claim the key first (unique insert), then post. A replay never
        // reaches post(), so no duplicate entry can be created.
        try {
            $claim = IdempotencyKey::create([
                'key'              => $idempotencyKey,
                'domain'           => $sourceDomain ?? 'ledger',
                'journal_entry_id' => null,
                'result'           => null,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Lost the claim race: wait briefly for the winner to attach
            // its entry, then return it.
            for ($attempt = 0; $attempt < 10; $attempt++) {
                usleep(100_000);
                $winner = IdempotencyKey::where('key', $idempotencyKey)->first();

                if ($winner !== null && $winner->journal_entry_id !== null) {
                    return JournalEntry::with('lines')->findOrFail($winner->journal_entry_id);
                }
            }

            throw new RuntimeException("Duplicate idempotency key '{$idempotencyKey}' is still being processed.", 0, $e);
        }

        $entry = $this->post($description, $lines, $sourceDomain, $sourceEventId, $idempotencyKey);

        $claim->update([
            'journal_entry_id' => $entry->id,
            'result'           => ['entry_id' => $entry->id, 'entry_number' => $entry->entry_number],
        ]);

        return $entry;
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
     * @param array<int, array{account_code: string, debit: mixed, credit: mixed, currency?: string, narrative?: string|null}> $lines
     * @param list<string> $guardAccountCodes Debit-normal account codes that must never go negative.
     */
    public function postGuarded(
        string $description,
        array $lines,
        array $guardAccountCodes,
        ?string $sourceDomain = null,
        ?string $sourceEventId = null,
    ): JournalEntry {
        $scale = $this->scale();
        $normalised = $this->normaliseLines($lines, $scale);

        // The lock, the posting, the balance re-check and the version bump
        // all happen in ONE transaction, so no concurrent writer can slip
        // a debit in between the check and the commit. The driver sync
        // runs after the transaction commits.
        $posted = null;
        DB::transaction(function () use ($description, $normalised, $guardAccountCodes, $sourceDomain, $sourceEventId, $scale, &$posted): void {
            // Lock the guarded account rows first: concurrent balance
            // mutations on the same account serialise here.
            $versions = [];
            foreach ($guardAccountCodes as $code) {
                $account = LedgerAccount::where('code', $code)->lockForUpdate()->first();

                if ($account === null) {
                    throw new RuntimeException("Guarded ledger account '{$code}' does not exist.");
                }

                $versions[$code] = (int) $account->version;
            }

            $posted = $this->persistEntry($description, $normalised, $sourceDomain, $sourceEventId);

            // Re-check balances after posting, still inside the transaction
            // and still holding the locks.
            foreach ($guardAccountCodes as $code) {
                $balance = $this->driver->balance($code);

                if (DecimalMath::cmp($balance['amount'], '0', $scale) < 0) {
                    throw new RuntimeException(
                        "Posting would overdraw ledger account '{$code}': resulting balance {$balance['amount']}."
                    );
                }

                $updated = LedgerAccount::where('code', $code)
                    ->where('version', $versions[$code])
                    ->update(['version' => $versions[$code] + 1]);

                if ($updated !== 1) {
                    throw new OptimisticConcurrencyException(
                        "Ledger account '{$code}' was modified concurrently (expected version {$versions[$code]})."
                    );
                }
            }
        });

        /** @var JournalEntry $posted */
        $this->driver->post($posted);

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
        $this->driver->post($reversal);

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
     * Normalise raw line amounts to exact decimal strings at the posting
     * scale and validate shape. No float arithmetic is performed anywhere:
     * DecimalString parses floats/exponent strings exactly and rejects
     * non-finite values.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array{account_code: string, debit: string, credit: string, currency: string, narrative: string|null}>
     */
    private function normaliseLines(array $lines, int $scale): array
    {
        if (count($lines) < 2) {
            throw new RuntimeException('A journal entry needs at least two lines.');
        }

        $normalised = [];
        foreach ($lines as $line) {
            $accountCode = (string) ($line['account_code'] ?? '');

            if ($accountCode === '') {
                throw new RuntimeException('Every journal line needs an account_code.');
            }

            $debit = DecimalString::fromNumeric($line['debit'] ?? null, $scale);
            $credit = DecimalString::fromNumeric($line['credit'] ?? null, $scale);

            if ($debit === null || $credit === null) {
                throw new RuntimeException('Each debit and credit must be a finite decimal number.');
            }

            if (DecimalMath::cmp($debit, '0', $scale) < 0 || DecimalMath::cmp($credit, '0', $scale) < 0) {
                throw new RuntimeException('Journal line amounts must not be negative.');
            }

            $normalised[] = [
                'account_code' => $accountCode,
                'debit'        => $debit,
                'credit'       => $credit,
                'currency'     => (string) ($line['currency'] ?? config('ledger.default_currency', 'USD')),
                'narrative'    => isset($line['narrative']) ? (string) $line['narrative'] : null,
            ];
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
