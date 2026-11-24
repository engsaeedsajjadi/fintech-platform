<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Console\Commands;

use App\Domain\Ledger\Models\JournalLine;
use App\Domain\Ledger\Services\ReconciliationService;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Reconcile posted journal entries against a provider statement file.
 *
 * Usage:
 *   php artisan ledger:reconcile --domain=payment --from=2026-11-01 --to=2026-11-24 --provider-statement=/path/to/statement.json
 *
 * The statement file is JSON: [{"reference": "...", "amount": "10.25", "currency": "USD"}].
 * Ledger rows are built from posted journal lines on ONE general-ledger
 * account (--account, required), keyed by the entry's source_event_id
 * (falling back to the entry number). The row amount is the line's net
 * movement on that account (credits minus debits), so a provider
 * statement for that account's settlement leg reconciles cleanly.
 * Reconciliation is per GL account, like ReconciliationService::reconcile().
 *
 * The command only REPORTS: it lists matched / ledger-only / provider-only
 * / amount-mismatch references and persists a ReconciliationReport with
 * status DISCREPANCY when anything mismatches. It never auto-corrects,
 * never posts an entry, and never changes a balance. Exit code 1 when
 * mismatches exist so schedulers/alerting can pick it up.
 */
final class ReconcileLedgerCommand extends Command
{
    protected $signature = 'ledger:reconcile
        {--domain=ledger : Domain the statement belongs to}
        {--account= : GL account code to reconcile (required)}
        {--from= : Period start (Y-m-d), default 30 days ago}
        {--to= : Period end (Y-m-d), default today}
        {--provider-statement= : Path to a JSON provider statement file}';

    protected $description = 'Compare posted journal entries against a provider statement and report mismatches (never auto-corrects).';

    public function handle(ReconciliationService $reconciliation): int
    {
        $from = $this->option('from') !== null
            ? Carbon::parse((string) $this->option('from'))->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();
        $to = $this->option('to') !== null
            ? Carbon::parse((string) $this->option('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        $statementPath = (string) ($this->option('provider-statement') ?? '');
        $accountCode = (string) ($this->option('account') ?? '');

        if ($accountCode === '') {
            $this->error('A GL account code is required: --account=2001');

            return self::FAILURE;
        }

        if ($statementPath === '' || ! is_file($statementPath)) {
            $this->error('A provider statement JSON file is required: --provider-statement=/path/to/statement.json');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($statementPath), true);

        if (! is_array($decoded)) {
            $this->error('Provider statement is not valid JSON.');

            return self::FAILURE;
        }

        $providerRows = [];
        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['reference'], $row['amount'], $row['currency'])) {
                $this->error('Each provider statement row needs reference, amount and currency.');

                return self::FAILURE;
            }

            $providerRows[] = [
                'reference' => (string) $row['reference'],
                'amount'    => (string) $row['amount'],
                'currency'  => (string) $row['currency'],
            ];
        }

        $ledgerRows = JournalLine::query()
            ->where('account_code', $accountCode)
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereBetween('posted_at', [$from, $to]))
            ->with('entry:id,entry_number,source_event_id')
            ->get()
            ->groupBy(fn (JournalLine $line): string => $line->entry->source_event_id ?? $line->entry->entry_number)
            ->map(fn ($lines, string $reference): array => [
                'reference' => $reference,
                // Net movement on this GL account (credits minus debits).
                'amount' => $lines->reduce(
                    fn (string $carry, JournalLine $line): string => DecimalMath::add($carry, DecimalMath::sub((string) $line->credit_amount, (string) $line->debit_amount, 4), 4),
                    '0'
                ),
                'currency' => (string) ($lines->first()->currency ?? 'USD'),
            ])
            ->values()
            ->all();

        $comparison = $reconciliation->compareStatements($ledgerRows, $providerRows);
        $domain = (string) $this->option('domain');

        $report = $reconciliation->recordMismatchReport($domain, $from, $to, $comparison);

        $this->info("Reconciliation report {$report->id} ({$report->status->value}) for domain '{$domain}', {$from->toDateString()} → {$to->toDateString()}.");
        $this->line('Matched: ' . count($comparison['matched']));

        if ($comparison['ledger_only'] !== []) {
            $this->warn('Ledger-only (provider silent — requires manual review): ' . implode(', ', $comparison['ledger_only']));
        }

        if ($comparison['provider_only'] !== []) {
            $this->warn('Provider-only (ledger silent — requires manual review): ' . implode(', ', $comparison['provider_only']));
        }

        foreach ($comparison['amount_mismatches'] as $mismatch) {
            $this->warn("Amount mismatch {$mismatch['reference']}: ledger {$mismatch['ledger_amount']} vs provider {$mismatch['provider_amount']} {$mismatch['currency']}");
        }

        $mismatchCount = count($comparison['ledger_only']) + count($comparison['provider_only']) + count($comparison['amount_mismatches']);

        if ($mismatchCount > 0) {
            $this->error("{$mismatchCount} mismatch(es) found. Nothing was auto-corrected; review report {$report->id}.");

            return self::FAILURE;
        }

        $this->info('No mismatches.');

        return self::SUCCESS;
    }
}
