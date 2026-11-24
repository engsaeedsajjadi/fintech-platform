<?php

declare(strict_types=1);

namespace App\Domain\International\Services;

use App\Domain\International\Enums\InternationalTransferStatus;
use App\Domain\International\Models\InternationalReconciliationReport;
use App\Domain\International\Models\InternationalTransfer;
use App\Domain\Ledger\ValueObjects\DecimalMath;
use App\Domain\Ledger\ValueObjects\MoneyAmount;
use App\Models\User;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Reconciliation for the International domain.
 *
 * Two checks, both exact bcmath (scale 4), never floats:
 * 1. Clearing reconciliation: the `intl:clearing:{CCY}` ledger balance
 *    (credit − debit, liability) must equal the sum of principal
 *    amounts of transfers currently in `processing` — the funds
 *    reserved and not yet settled, failed or reversed. Any variance is
 *    a discrepancy for manual review.
 * 2. Statement matching: external statement rows (reference, amount,
 *    currency — supplied by an operator from the provider statement)
 *    are matched against settled transfers by reference + amount.
 *    Unmatched rows and unsettled transfers are listed in the report
 *    details; they never auto-correct anything.
 */
final class InternationalReconciliationService
{
    public function __construct(
        private readonly InternationalAccountService $accounts,
        private readonly InternationalAuditService $audit,
    ) {
    }

    public function reconcileClearing(
        string $currency,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?User $operator = null,
    ): InternationalReconciliationReport {
        $currency = strtoupper($currency);

        if (! InternationalAccountService::isSupportedCurrency($currency)) {
            throw new InvalidArgumentException("Currency '{$currency}' is not supported.");
        }

        $glCode = "intl:clearing:{$currency}";
        $totals = $this->accounts->postedTotals($glCode);
        // Liability account: balance is credit − debit.
        $glBalance = DecimalMath::sub($totals['credit'], $totals['debit'], 4);

        $openPrincipalMinor = (int) InternationalTransfer::where('currency', $currency)
            ->where('status', InternationalTransferStatus::PROCESSING)
            ->sum('amount_minor');
        $domainBalance = MoneyAmount::fromMinorUnits($openPrincipalMinor, $currency)->decimal;
        $domainBalance = DecimalMath::add($domainBalance, '0', 4);

        $variance = DecimalMath::sub($glBalance, $domainBalance, 4);
        $status = DecimalMath::cmp($variance, '0', 4) === 0
            ? InternationalReconciliationReport::STATUS_MATCHED
            : InternationalReconciliationReport::STATUS_DISCREPANCY;

        $report = InternationalReconciliationReport::create([
            'period_start'    => $periodStart->toDateString(),
            'period_end'      => $periodEnd->toDateString(),
            'domain'          => 'international',
            'gl_account_code' => $glCode,
            'gl_balance'      => $glBalance,
            'domain_balance'  => $domainBalance,
            'variance'        => $variance,
            'status'          => $status,
            'details'         => [
                'check'                           => 'clearing',
                'currency'                        => $currency,
                'open_processing_transfers_minor' => $openPrincipalMinor,
            ],
        ]);

        $this->audit->log(null, $operator !== null ? (int) $operator->id : null, 'reconciliation.clearing', [
            'report_id' => $report->id,
            'currency'  => $currency,
            'status'    => $status,
            'variance'  => $variance,
        ]);

        return $report;
    }

    /**
     * Match external statement rows against settled transfers.
     *
     * @param array<int, array{reference: string, amount_minor: int, currency: string}> $rows
     */
    public function matchStatement(array $rows, ?User $operator = null): InternationalReconciliationReport
    {
        $matched = [];
        $unmatchedRows = [];
        $settledByReference = InternationalTransfer::where('status', InternationalTransferStatus::SETTLED)
            ->get()
            ->keyBy('reference');

        foreach ($rows as $row) {
            $reference = (string) ($row['reference'] ?? '');
            $transfer = $settledByReference->get($reference);

            if (
                $transfer !== null
                && (int) ($row['amount_minor'] ?? -1) === (int) $transfer->amount_minor
                && strtoupper((string) ($row['currency'] ?? '')) === $transfer->currency
            ) {
                $matched[] = $reference;
            } else {
                $unmatchedRows[] = $row;
            }
        }

        $matchedReferences = collect($matched);
        $unmatchedSettled = InternationalTransfer::where('status', InternationalTransferStatus::SETTLED)
            ->whereNotIn('reference', $matchedReferences->all())
            ->pluck('reference')
            ->all();

        $status = $unmatchedRows === [] && $unmatchedSettled === []
            ? InternationalReconciliationReport::STATUS_MATCHED
            : InternationalReconciliationReport::STATUS_DISCREPANCY;

        $report = InternationalReconciliationReport::create([
            'period_start'    => now()->toDateString(),
            'period_end'      => now()->toDateString(),
            'domain'          => 'international',
            'gl_account_code' => 'statement',
            'gl_balance'      => '0.0000',
            'domain_balance'  => '0.0000',
            'variance'        => '0.0000',
            'status'          => $status,
            'details'         => [
                'check'                       => 'statement',
                'matched'                     => $matched,
                'unmatched_statement_rows'    => $unmatchedRows,
                'unmatched_settled_transfers' => $unmatchedSettled,
            ],
        ]);

        $this->audit->log(null, $operator !== null ? (int) $operator->id : null, 'reconciliation.statement', [
            'report_id'         => $report->id,
            'status'            => $status,
            'matched'           => count($matched),
            'unmatched_rows'    => count($unmatchedRows),
            'unmatched_settled' => count($unmatchedSettled),
        ]);

        return $report;
    }
}
