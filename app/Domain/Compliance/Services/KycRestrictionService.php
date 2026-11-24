<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Kyc\Exceptions\KycRestrictionException;
use App\Models\User;

/**
 * Enforces user restrictions by KYC verification status.
 *
 * The matrix lives in config/kyc.php (`restrictions`). Rules:
 *
 *  - No KYC (not_submitted / rejected / expired): no money-moving action.
 *  - Pending: deposit only — funds are held and cannot be withdrawn,
 *    transferred, or used for cards / trading until approval.
 *  - Approved: deposit / transfer / withdraw / card_issue / trade, within
 *    the tier limits from KycService::getRequirements().
 *  - A sanctions flag or a high risk rating restricts the user regardless
 *    of KYC status (defence in depth; the flag lives on the risk profile /
 *    user record, not on this matrix).
 *
 * This is a policy service, not middleware: call assertAllowed() before
 * any money-moving operation, and isAllowed() for UI gating.
 */
final class KycRestrictionService
{
    /** Money-moving and privileged actions, and the minimum status they need. */
    public const ACTIONS = ['deposit', 'transfer', 'withdraw', 'card_issue', 'trade'];

    /** @return list<string> */
    public function allowedActions(User $user): array
    {
        if ($this->isBlockedByRisk($user)) {
            return [];
        }

        $status = $user->kyc_status ?? 'not_submitted';

        /** @var array<string, list<string>> $matrix */
        $matrix = (array) config('kyc.restrictions', []);

        return $matrix[$status] ?? [];
    }

    public function isAllowed(User $user, string $action): bool
    {
        return in_array($action, $this->allowedActions($user), true);
    }

    /** @throws KycRestrictionException */
    public function assertAllowed(User $user, string $action): void
    {
        if (! $this->isAllowed($user, $action)) {
            throw KycRestrictionException::for($action, (string) ($user->kyc_status ?? 'not_submitted'));
        }
    }

    /** A sanctions hit or high risk rating blocks everything, even if KYC is approved. */
    public function isBlockedByRisk(User $user): bool
    {
        if ((bool) ($user->kyc_data['sanctions_match'] ?? false)) {
            return true;
        }

        return (string) ($user->risk_rating ?? '') === 'high'
            && (bool) ($user->kyc_data['risk_block'] ?? false);
    }
}
