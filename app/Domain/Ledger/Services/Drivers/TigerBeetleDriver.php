<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services\Drivers;

use App\Domain\Ledger\Contracts\LedgerDriverInterface;
use App\Domain\Ledger\Exceptions\LedgerUnavailableException;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TigerBeetle driver (external ledger sync).
 *
 * Fail-closed (Phase 4.2-E): a balance/history lookup that cannot reach
 * TigerBeetle THROWS LedgerUnavailableException. The previous version
 * returned a fabricated '0.0000' balance and an empty history, which let
 * callers — including the overdraw guard — act on money that was not there.
 * No cross-system atomic commit is assumed: the DB journal (written first
 * by LedgerService) is the source of truth, and a failed sync is recorded
 * in the driver-sync outbox for retry instead of failing the posting.
 *
 * Transfer IDs are deterministic (hash of entry id + pair index), so
 * retrying the same entry reuses the same transfer IDs and TigerBeetle
 * deduplicates instead of double-posting.
 */
final class TigerBeetleDriver implements LedgerDriverInterface
{
    private string $baseUrl;

    private int $clusterId;

    private int $timeoutMs;

    public function __construct(
        private readonly ?EloquentDriver $eloquent = null,
    ) {
        $address = (string) config('ledger.tigerbeetle.addresses', '127.0.0.1:3001');
        $this->baseUrl = "http://{$address}";
        $this->clusterId = (int) config('ledger.tigerbeetle.cluster_id', 0);
        $this->timeoutMs = (int) config('ledger.tigerbeetle.timeout_ms', 5000);
    }

    public function post(JournalEntry $entry): void
    {
        $entry->loadMissing('lines');

        // A balanced entry can have N debit legs and M credit legs. The old
        // code paired debitLines[$i] with creditLines[$i] positionally,
        // which mis-assigns money whenever the counts differ (e.g. a
        // 3-line fee entry). Decompose by amount instead: walk both sides
        // and pair min(remaining) at each step, so the transfers sum to
        // exactly the entry's debits and credits.
        /** @var array<int, array{code: string, minor: int}> $debits */
        $debits = [];
        /** @var array<int, array{code: string, minor: int}> $credits */
        $credits = [];

        foreach ($entry->lines as $line) {
            // Decimal major units (scale 4) -> integer minor units at 10^4.
            // bcmul with scale 0 truncates any sub-minor remainder;
            // LedgerService already normalised lines to scale 4, so the
            // conversion is exact.
            if (DecimalMath::cmp((string) $line->debit_amount, '0', 4) > 0) {
                $debits[] = [
                    'code'  => $line->account_code,
                    'minor' => (int) DecimalMath::mul((string) $line->debit_amount, '10000', 0),
                ];
            }

            if (DecimalMath::cmp((string) $line->credit_amount, '0', 4) > 0) {
                $credits[] = [
                    'code'  => $line->account_code,
                    'minor' => (int) DecimalMath::mul((string) $line->credit_amount, '10000', 0),
                ];
            }
        }

        $tbTransfers = [];
        $di = 0;
        $ci = 0;

        while ($di < count($debits) && $ci < count($credits)) {
            /** @var array{code: string, minor: int} $debit */
            $debit = $debits[$di];
            /** @var array{code: string, minor: int} $credit */
            $credit = $credits[$ci];

            $amount = min($debit['minor'], $credit['minor']);
            $index = count($tbTransfers);

            $tbTransfers[] = [
                'id'                => $this->transferId($entry->id, $index),
                'debit_account_id'  => $this->accountCodeToId($debit['code']),
                'credit_account_id' => $this->accountCodeToId($credit['code']),
                'amount'            => $amount,
                'ledger'            => $this->clusterId,
                'code'              => crc32($entry->source_domain ?? 'manual'),
                'user_data_128'     => $this->uuidToInt128($entry->id),
                'flags'             => 0,
            ];

            $debits[$di]['minor'] -= $amount;
            $credits[$ci]['minor'] -= $amount;

            if ($debits[$di]['minor'] === 0) {
                $di++;
            }

            if ($credits[$ci]['minor'] === 0) {
                $ci++;
            }
        }

        if ($tbTransfers === []) {
            throw new RuntimeException("TigerBeetle sync refused: entry {$entry->id} has no positive debit/credit legs.");
        }

        try {
            $response = Http::timeout(max(1, (int) ($this->timeoutMs / 1000)))
                ->post("{$this->baseUrl}/create_transfers", $tbTransfers);

            if (! $response->successful()) {
                throw new RuntimeException("TigerBeetle create_transfers failed: {$response->status()}");
            }

            // TigerBeetle answers 200 with a per-transfer result array:
            // empty means every transfer landed; any element means that
            // transfer failed. HTTP success alone is NOT success.
            $results = $response->json();

            if ($results !== null && $results !== []) {
                if (! is_array($results)) {
                    throw new RuntimeException('TigerBeetle create_transfers returned an unexpected body.');
                }

                throw new RuntimeException(
                    'TigerBeetle rejected transfers: ' . mb_substr((string) json_encode($results), 0, 500)
                );
            }

            Log::info('TigerBeetle transfers posted', [
                'entry_id'       => $entry->id,
                'transfer_count' => count($tbTransfers),
            ]);
        } catch (ConnectionException $e) {
            throw new LedgerUnavailableException("TigerBeetle connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @return array{amount: string, currency: string}
     *
     * @throws LedgerUnavailableException when TigerBeetle cannot be reached
     * or does not know the account — never a fabricated zero.
     */
    public function balance(string $accountCode, ?Carbon $asOf = null): array
    {
        $accountId = $this->accountCodeToId($accountCode);

        try {
            $response = Http::timeout(max(1, (int) ($this->timeoutMs / 1000)))
                ->post("{$this->baseUrl}/lookup_accounts", [$accountId]);

            if (! $response->successful()) {
                throw new LedgerUnavailableException(
                    "TigerBeetle lookup_accounts failed for '{$accountCode}': HTTP {$response->status()}."
                );
            }

            $accounts = $response->json();

            if (empty($accounts) || ! is_array($accounts)) {
                throw new LedgerUnavailableException(
                    "TigerBeetle has no account for '{$accountCode}': balance unknown, refusing to report zero."
                );
            }

            $account = $accounts[0] ?? [];
            $debits = (int) ($account['debits_posted'] ?? 0);
            $credits = (int) ($account['credits_posted'] ?? 0);
            // Integer minor units (10^4) -> decimal major units, exactly.
            $balance = DecimalMath::div((string) ($debits - $credits), '10000', 4);

            return [
                'amount'   => $balance,
                'currency' => (string) config('ledger.default_currency', 'USD'),
            ];
        } catch (ConnectionException $e) {
            throw new LedgerUnavailableException(
                "TigerBeetle lookup_accounts unreachable for '{$accountCode}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * TigerBeetle doesn't support point-in-time queries natively, so the
     * trial balance is served from the DB journal (the source of truth)
     * via the Eloquent driver — never an empty report that looks like a
     * clean book.
     *
     * @return array<string, array{debit: string, credit: string, balance: string}>
     */
    public function trialBalance(?Carbon $asOf = null): array
    {
        return ($this->eloquent ?? new EloquentDriver())->trialBalance($asOf);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     *
     * @throws LedgerUnavailableException when TigerBeetle cannot be reached
     * — never a silent empty history.
     */
    public function accountHistory(string $accountCode, Carbon $from, Carbon $to): Collection
    {
        $accountId = $this->accountCodeToId($accountCode);

        try {
            $response = Http::timeout(max(1, (int) ($this->timeoutMs / 1000)))
                ->post("{$this->baseUrl}/get_account_transfers", [
                    'account_id'    => $accountId,
                    'timestamp_min' => $from->getTimestampMs(),
                    'timestamp_max' => $to->getTimestampMs(),
                    'limit'         => 1000,
                ]);

            if (! $response->successful()) {
                throw new LedgerUnavailableException(
                    "TigerBeetle get_account_transfers failed for '{$accountCode}': HTTP {$response->status()}."
                );
            }

            return collect($response->json() ?? []);
        } catch (ConnectionException $e) {
            throw new LedgerUnavailableException(
                "TigerBeetle get_account_transfers unreachable for '{$accountCode}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Deterministic transfer id: the same entry + pair index always maps
     * to the same id, so a retried sync cannot double-post. 60 bits, never 0.
     */
    private function transferId(string $entryId, int $index): int
    {
        $id = (int) hexdec(substr(md5($entryId . ':' . $index), 0, 15));

        return $id === 0 ? 1 : $id;
    }

    /**
     * Deterministic mapping: account code → TigerBeetle u128 ID.
     * Uses CRC32 for simplicity (production would use a persistent mapping table).
     * NOTE: CRC32 collisions are possible — two codes can map to one TB
     * account. A persistent mapping table is the remaining fix (see report).
     */
    private function accountCodeToId(string $code): int
    {
        return abs(crc32($code));
    }

    /**
     * Convert a UUID string to a 63-bit integer for TigerBeetle user_data_128.
     */
    private function uuidToInt128(string $uuid): int
    {
        $hex = str_replace('-', '', $uuid);

        return abs((int) hexdec(substr($hex, 0, 15)));
    }
}
