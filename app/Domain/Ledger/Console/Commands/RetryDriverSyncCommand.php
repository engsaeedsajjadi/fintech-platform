<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Console\Commands;

use App\Domain\Ledger\Services\ReconciliationService;
use Illuminate\Console\Command;

/**
 * Replay pending external-driver syncs from the outbox.
 *
 * Usage:
 *   php artisan ledger:retry-driver-sync [--limit=50]
 *
 * The DB journal is authoritative; a pending row only exists because a
 * driver sync failed after the journal commit. Retries re-send the entry
 * with the same deterministic transfer ids, so TigerBeetle deduplicates
 * instead of double-posting. Exit code 0 when every retried row synced,
 * 1 when any row is still pending/failed. This command never posts a
 * journal entry and never changes a balance.
 *
 * The driver is resolved from the same container binding used by LedgerService.
 */
final class RetryDriverSyncCommand extends Command
{
    protected $signature = 'ledger:retry-driver-sync
        {--limit=50 : Maximum pending rows to retry}';

    protected $description = 'Replay pending external ledger-driver syncs from the outbox (never posts entries or changes balances).';

    public function handle(): int
    {
        $service = app(ReconciliationService::class);
        $result = $service->retryPendingDriverSyncs((int) $this->option('limit'));

        $this->info("Driver sync retry: {$result['retried']} retried, {$result['synced']} synced, {$result['failed']} still failing.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
