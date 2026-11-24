<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Contracts\LedgerDriverInterface;
use App\Domain\Ledger\Enums\ReconciliationStatus;
use App\Domain\Ledger\Models\ReconciliationReport;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

final class ReconciliationService
{
    public function __construct(
        private readonly LedgerDriverInterface $driver,
    ) {
    }

    /**
     * Run reconciliation for a specific domain and GL account.
     */
    public function reconcile(
        string $domain,
        string $glAccountCode,
        string $domainBalance,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): ReconciliationReport {
        $glBalance = $this->driver->balance($glAccountCode, $periodEnd);
        $glAmount = DecimalMath::add(is_numeric($glBalance['amount']) ? $glBalance['amount'] : '0', '0', 4);
        $numericDomainBalance = DecimalMath::add(is_numeric($domainBalance) ? $domainBalance : '0', '0', 4);
        $variance = DecimalMath::sub($glAmount, $numericDomainBalance, 4);
        $status = DecimalMath::cmp($variance, '0', 4) === 0
            ? ReconciliationStatus::MATCHED
            : ReconciliationStatus::DISCREPANCY;

        return ReconciliationReport::create([
            'period_start'   => $periodStart,
            'period_end'     => $periodEnd,
            'domain'         => $domain,
            'gl_balance'     => $glAmount,
            'domain_balance' => $domainBalance,
            'variance'       => $variance,
            'status'         => $status,
        ]);
    }

    /**
     * Compare posted ledger rows against provider statement rows and list
     * every mismatch for manual review.
     *
     * Each row is ['reference' => string, 'amount' => decimal string in
     * major units, 'currency' => string]. Amounts are compared with bcmath
     * at scale 4 — never floats.
     *
     * This method NEVER auto-corrects anything: it only classifies. A
     * mismatch is resolved by a human marking the report reviewed (see
     * resolveDiscrepancy()) and, if money actually moved, by a separate
     * reversing/adjusting entry posted through LedgerService.
     *
     * @param array<int, array{reference: string, amount: string, currency: string}> $ledgerRows
     * @param array<int, array{reference: string, amount: string, currency: string}> $providerRows
     * @return array{matched: list<string>, ledger_only: list<string>, provider_only: list<string>, amount_mismatches: list<array{reference: string, ledger_amount: string, provider_amount: string, currency: string}>}
     */
    public function compareStatements(array $ledgerRows, array $providerRows): array
    {
        $ledgerByRef = [];
        foreach ($ledgerRows as $row) {
            $ledgerByRef[(string) $row['reference']] = $row;
        }

        $providerByRef = [];
        foreach ($providerRows as $row) {
            $providerByRef[(string) $row['reference']] = $row;
        }

        $matched = [];
        $ledgerOnly = [];
        $providerOnly = [];
        $amountMismatches = [];

        foreach ($ledgerByRef as $reference => $ledgerRow) {
            if (! isset($providerByRef[$reference])) {
                // Money left the ledger, the provider is silent: hold for
                // manual review, never assume it failed.
                $ledgerOnly[] = $reference;
                continue;
            }

            $providerRow = $providerByRef[$reference];

            if (strtoupper((string) $ledgerRow['currency']) !== strtoupper((string) $providerRow['currency'])) {
                $amountMismatches[] = [
                    'reference'       => $reference,
                    'ledger_amount'   => (string) $ledgerRow['amount'],
                    'provider_amount' => (string) $providerRow['amount'],
                    'currency'        => (string) $ledgerRow['currency'] . ' vs ' . (string) $providerRow['currency'],
                ];
                continue;
            }

            if (DecimalMath::cmp((string) $ledgerRow['amount'], (string) $providerRow['amount'], 4) === 0) {
                $matched[] = $reference;
            } else {
                $amountMismatches[] = [
                    'reference'       => $reference,
                    'ledger_amount'   => (string) $ledgerRow['amount'],
                    'provider_amount' => (string) $providerRow['amount'],
                    'currency'        => (string) $ledgerRow['currency'],
                ];
            }
        }

        foreach ($providerByRef as $reference => $providerRow) {
            if (! isset($ledgerByRef[$reference])) {
                // Money arrived at the provider, the ledger is silent.
                $providerOnly[] = $reference;
            }
        }

        return [
            'matched'           => $matched,
            'ledger_only'       => $ledgerOnly,
            'provider_only'     => $providerOnly,
            'amount_mismatches' => $amountMismatches,
        ];
    }

    /**
     * Persist a mismatch report for manual review. The report records the
     * discrepancy; it changes no balance and posts no entry.
     *
     * @param array{matched: array<int, string>, ledger_only: array<int, mixed>, provider_only: array<int, mixed>, amount_mismatches: array<int, array<string, mixed>>} $comparison
     */
    public function recordMismatchReport(
        string $domain,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $comparison,
    ): ReconciliationReport {
        $mismatchCount = count($comparison['ledger_only'])
            + count($comparison['provider_only'])
            + count($comparison['amount_mismatches']);

        return ReconciliationReport::create([
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'domain'       => $domain,
            // A statement comparison has no single GL/domain balance; the
            // detail lives in notes. Balances stay zero so nothing here can
            // be mistaken for a computed variance.
            'gl_balance'     => '0.0000',
            'domain_balance' => '0.0000',
            'variance'       => '0.0000',
            'status'         => $mismatchCount === 0 ? ReconciliationStatus::MATCHED : ReconciliationStatus::DISCREPANCY,
            'notes'          => json_encode([
                'mismatch_count'    => $mismatchCount,
                'matched'           => $comparison['matched'],
                'ledger_only'       => $comparison['ledger_only'],
                'provider_only'     => $comparison['provider_only'],
                'amount_mismatches' => $comparison['amount_mismatches'],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Get reconciliation history for a domain.
     *
     * @return Collection<int, ReconciliationReport>
     */
    public function getHistory(string $domain, int $limit = 30): Collection
    {
        return ReconciliationReport::where('domain', $domain)
            ->orderByDesc('period_end')
            ->limit($limit)
            ->get();
    }

    /**
     * Mark a discrepancy as resolved.
     *
     * Resolving means a human reviewed the mismatch and recorded what was
     * decided. It does NOT change any balance and posts no journal entry:
     * if money actually moved, the correcting entry must be posted
     * separately through LedgerService (and linked in the notes).
     */
    public function resolveDiscrepancy(string $reportId, string $notes): ReconciliationReport
    {
        $report = ReconciliationReport::findOrFail($reportId);
        $report->update([
            'status' => ReconciliationStatus::RESOLVED,
            'notes'  => $notes,
        ]);

        return $report->refresh();
    }
}
