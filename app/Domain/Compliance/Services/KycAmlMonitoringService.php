<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Contracts\SanctionsScreeningInterface;
use App\Domain\Compliance\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * AML monitoring integration point for the KYC workflow.
 *
 * Delegates sanctions screening to the existing
 * SanctionsScreeningInterface (Composite: Internal list first, then
 * GoPlus, then Chainalysis when configured — see
 * ComplianceServiceProvider). This service does NOT duplicate that seam
 * and does NOT maintain its own sanctions list.
 *
 * On a match the user is flagged in `kyc_data.sanctions_match` (which
 * KycRestrictionService treats as a full block) and the verification is
 * routed to manual review. A match is never auto-cleared here: only a
 * human reviewer can clear it after investigating the false-positive /
 * true-match workflow. Only counts and list names are logged — never the
 * screened person's details beyond what the adapter itself needs.
 */
final class KycAmlMonitoringService
{
    public function __construct(
        private readonly SanctionsScreeningInterface $screening,
    ) {
    }

    /**
     * Screen a user against sanctions lists at KYC time.
     *
     * @return array{total_matches: int, lists_checked: list<string>, requires_manual_review: bool, provider: string}
     */
    public function screenUser(User $user): array
    {
        $result = $this->screening->screenIndividual([
            'name'        => $user->name,
            'nationality' => $user->country_code,
        ]);

        $totalMatches = (int) ($result['total_matches'] ?? 0);
        /** @var list<string> $listsChecked */
        $listsChecked = array_values($result['lists_checked'] ?? []);
        $requiresReview = $totalMatches > 0;

        $kycData = $user->kyc_data ?? [];
        $kycData['sanctions_match'] = $requiresReview;
        $kycData['sanctions_screened_at'] = now()->toIso8601String();
        $user->update(['kyc_data' => $kycData]);

        Log::info('AML sanctions screening completed for KYC', [
            'user_uuid'     => $user->uuid,
            'provider'      => $this->screening->getName(),
            'total_matches' => $totalMatches,
            'lists_checked' => $listsChecked,
        ]);

        AuditLog::log(
            $requiresReview ? 'aml.sanctions_match' : 'aml.sanctions_clear',
            $user,
            null,
            ['sanctions_match' => $requiresReview],
            ['total_matches'   => $totalMatches, 'lists_checked' => $listsChecked, 'provider' => $this->screening->getName()],
            'aml,compliance,sanctions'
        );

        return [
            'total_matches'          => $totalMatches,
            'lists_checked'          => $listsChecked,
            'requires_manual_review' => $requiresReview,
            'provider'               => $this->screening->getName(),
        ];
    }

    /**
     * Screen a blockchain address (e.g. a deposit address supplied at KYC).
     *
     * @return array{total_matches: int, lists_checked: list<string>, requires_manual_review: bool, provider: string}
     */
    public function screenAddress(string $address, string $chain): array
    {
        $result = $this->screening->screenAddress($address, $chain);

        $totalMatches = (int) ($result['total_matches'] ?? 0);

        return [
            'total_matches'          => $totalMatches,
            'lists_checked'          => array_values($result['lists_checked'] ?? []),
            'requires_manual_review' => $totalMatches > 0,
            'provider'               => $this->screening->getName(),
        ];
    }
}
