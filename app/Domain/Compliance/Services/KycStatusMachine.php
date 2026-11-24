<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Kyc\Exceptions\InvalidKycTransitionException;
use App\Domain\Compliance\Models\AuditLog;
use App\Models\User;
use InvalidArgumentException;

/**
 * Provider-independent KYC state machine for the user-level status
 * (`users.kyc_status`).
 *
 * Canonical states: pending, submitted, requires_manual_review, approved,
 * rejected, expired.
 *
 * Storage mapping (no migration is introduced by this phase):
 *
 *  - `submitted` is stored as `pending`. The `users.kyc_status` column is
 *    a DB enum (`not_started`, `pending`, `in_review`, `approved`,
 *    `rejected`, `expired`) that has no `submitted` value, and the
 *    restrictions matrix, `User::needsKyc()` / `hasCompletedKyc()` and
 *    several consumers key off `pending`. Renaming the stored value would
 *    need a migration plus a sweep of those consumers; the machine
 *    therefore treats `pending` as the stored representation of the
 *    `submitted` state and exposes both names.
 *  - `requires_manual_review` is a state of the *verification* record
 *    (`KycVerification::STATUS_REQUIRES_MANUAL_REVIEW`), governed by
 *    KycManualReviewService / EnhancedKycService, while the user-level
 *    status stays `pending` until a human or a provider verdict decides.
 *    The machine's `approve()` accepts a user whose verification is in
 *    manual review (user status `pending`) with basis `manual_review`.
 *  - `not_started` (the column default) and `not_submitted` are the same
 *    initial state; the machine accepts both spellings on read.
 *
 * Rules enforced here, nowhere else:
 *
 *  - Approval requires a basis: `provider_verdict` (a real provider's
 *    verdict — signature-verified webhook re-confirmed against the
 *    provider API) or `manual_review` (a human decision via
 *    KycManualReviewService). Anything else throws. A simulated result
 *    is never a basis.
 *  - Rejection requires a reason.
 *  - Every transition writes an `AuditLog` row (`kyc.status_transition`)
 *    with from/to, actor and basis — including transitions driven by
 *    provider webhooks. No Compliance code writes `users.kyc_status`
 *    directly anymore; it goes through this machine (via KycService).
 *  - Illegal transitions throw InvalidKycTransitionException instead of
 *    silently moving the user (fail closed).
 *
 * Allowed transitions:
 *
 *  - submit:  not_started | not_submitted | rejected | expired -> pending
 *  - approve: pending -> approved (basis manual_review | provider_verdict)
 *             not_started | not_submitted | rejected | expired -> approved
 *             (basis provider_verdict only: an async provider webhook may
 *             arrive without a local submit record, and a current,
 *             API-reconfirmed provider verdict supersedes a stale local
 *             rejection / expiry; the verification-record guard — Ondato
 *             OPEN_STATUSES, completeVerification's sandbox refusal —
 *             is what stops a stale verdict from approving)
 *  - reject:  pending | approved | not_started | not_submitted -> rejected
 *             (a rejection may follow an approval — it takes access away)
 *  - expire:  approved | pending -> expired
 */
final class KycStatusMachine
{
    public const ACTION_TRANSITION = 'kyc.status_transition';

    public const BASIS_PROVIDER_VERDICT = 'provider_verdict';

    public const BASIS_MANUAL_REVIEW = 'manual_review';

    /** Stored value of the canonical `submitted` state. @see class docblock. */
    public const STORED_SUBMITTED = 'pending';

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'pending'  => ['not_started', 'not_submitted', 'rejected', 'expired'],
        'approved' => ['pending', 'not_started', 'not_submitted', 'rejected', 'expired'],
        'rejected' => ['pending', 'approved', 'not_started', 'not_submitted'],
        'expired'  => ['approved', 'pending'],
    ];

    /** From-states a provider verdict may approve from (see class docblock). */
    private const PROVIDER_VERDICT_FROM = ['pending', 'not_started', 'not_submitted', 'rejected', 'expired'];

    /** From-states a manual-review approval may approve from. */
    private const MANUAL_REVIEW_FROM = ['pending'];

    /** Submit documents: (re)opens the verification. Idempotent while pending. */
    public function submit(User $user, string $actor): void
    {
        if ($this->current($user) === 'pending') {
            return;
        }

        $this->transition($user, 'pending', $actor, 'submit');
    }

    /**
     * Approve the user.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidArgumentException when $basis is not a real approval basis.
     */
    public function approve(User $user, string $actor, string $basis, array $context = []): void
    {
        if (! in_array($basis, [self::BASIS_PROVIDER_VERDICT, self::BASIS_MANUAL_REVIEW], true)) {
            throw new InvalidArgumentException(sprintf(
                'KYC approval requires a real basis ("provider_verdict" or "manual_review"); got "%s". Simulated results can never approve a user.',
                $basis
            ));
        }

        $from = $this->current($user);

        if ($basis === self::BASIS_MANUAL_REVIEW && ! in_array($from, self::MANUAL_REVIEW_FROM, true)) {
            throw InvalidKycTransitionException::from(
                $from,
                'approved',
                'A manual-review approval requires a pending submission.'
            );
        }

        if ($basis === self::BASIS_PROVIDER_VERDICT && ! in_array($from, self::PROVIDER_VERDICT_FROM, true)) {
            throw InvalidKycTransitionException::from(
                $from,
                'approved',
                'A provider verdict cannot approve from this state.'
            );
        }

        $this->transition($user, 'approved', $actor, $basis, $context);
    }

    /** Reject the user. A reason is mandatory — silent rejections hide appeals. */
    public function reject(User $user, string $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('KYC rejection requires a reason.');
        }

        $this->transition($user, 'rejected', $actor, 'rejection', ['reason' => $reason]);
    }

    /** Expire the user's KYC (approval lapsed, or the verification expired while pending). */
    public function expire(User $user, string $actor = 'system'): void
    {
        $this->transition($user, 'expired', $actor, 'expiry');
    }

    /**
     * The single writer of `users.kyc_status` in the Compliance domain.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidKycTransitionException when the move is not allowed.
     */
    public function transition(User $user, string $to, string $actor, string $basis, array $context = []): void
    {
        $from = $this->current($user);

        if ($from === $to) {
            throw InvalidKycTransitionException::from($from, $to, 'Already in that state.');
        }

        if (! in_array($from, self::ALLOWED[$to] ?? [], true)) {
            throw InvalidKycTransitionException::from($from, $to);
        }

        $user->update(['kyc_status' => $to]);

        AuditLog::log(
            self::ACTION_TRANSITION,
            $user,
            ['kyc_status' => $from],
            ['kyc_status' => $to],
            array_merge(['actor' => $actor, 'basis' => $basis], $context),
            'kyc,compliance,status'
        );
    }

    /** Normalises the legacy `not_started` default to the canonical initial state. */
    public function current(User $user): string
    {
        return $user->kyc_status ?? 'not_submitted';
    }
}
