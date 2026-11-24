<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Console\Commands;

use App\Domain\Ledger\Contracts\LedgerDriverInterface;
use App\Domain\Ledger\Services\Drivers\EloquentDriver;
use App\Domain\Ledger\Services\Drivers\TigerBeetleDriver;
use App\Domain\Ledger\Services\ReconciliationService;
use Illuminate\Console\Command;
use RuntimeException;

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
 * NOTE: the driver is resolved from `ledger.driver` here, not from the
 * container binding — AppServiceProvider hardcodes the Eloquent driver
 * and ignores that config key (known gap, see report).
 */
final class RetryDriverSyncCommand extends Command
{
    protected $signature = 'ledger:retry-driver-sync
        {--limit=50 : Maximum pending rows to retry}';

    protected $description = 'Replay pending external ledger-driver syncs from the outbox (never posts entries or changes balances).';

    public function handle(): int
    {
        $driver = $this->resolveDriver();
        $service = new ReconciliationService($driver);

        $result = $service->retryPendingDriverSyncs((int) $this->option('limit'));

        $this->info("Driver sync retry: {$result['retried']} retried, {$result['synced']} synced, {$result['failed']} still failing.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveDriver(): LedgerDriverInterface
    {
        return match (strtolower((string) config('ledger.driver', 'eloquent'))) {
            'tigerbeetle', 'tiger_beetle' => new TigerBeetleDriver(),
            'eloquent'                    => new EloquentDriver(),
            default                       => throw new RuntimeException(
                "Unknown ledger driver '" . (string) config('ledger.driver') . "' — expected 'eloquent' or 'tigerbeetle'."
            ),
        };
    }
}
