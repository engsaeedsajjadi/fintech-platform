<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Contracts;

/**
 * Extensible Iranian KYC provider seam.
 *
 * Covers the checks an Iranian onboarding flow needs that the generic
 * {@see KycProviderInterface} does not name: national identity
 * (Iranian national code), mobile-number ownership, bank-account
 * ownership, document verification, and human review.
 *
 * What this interface does NOT do: it never claims access to Iranian
 * government databases. No such access exists in-repo — no connection
 * to the National Organization for Civil Registration, no Shahkar
 * (mobile-ownership registry) integration, no bank / PSP ownership
 * API is contracted. Any future real adapter must be built on an
 * actual commercial / government contract and its credentials; until
 * then the only implementation is the sandbox adapter, which always
 * returns `requires_manual_review` and never approves anyone.
 *
 * Two honesty rules apply to every implementation:
 *
 *  - `validateNationalId()` checks only the *format* of an Iranian
 *    national code (10 digits + checksum). A passing checksum proves
 *    the number is well-formed, NOT that it belongs to the user or
 *    exists in any registry. It must never be presented as identity
 *    verification on its own.
 *  - No method may persist a full national code, full mobile number,
 *    or full account number / IBAN. Only last-4 + SHA-256 hash (as in
 *    KycVerificationWorkflowService) or provider references.
 *
 * Canonical result shape (same as IdentityVerificationProviderInterface):
 *
 *   [
 *     'status'   => 'approved'|'rejected'|'pending'|'requires_manual_review'|'not_verified',
 *     'verified' => bool,   // true ONLY for a real provider's approved verdict
 *     'sandbox'  => bool,   // true when the result came from a sandbox adapter
 *     'provider' => string, // stable provider name, e.g. 'iranian_sandbox'
 *     'reason'   => ?string,
 *     'metadata' => array,  // provider detail; never raw PII
 *   ]
 */
interface IranianKycProviderInterface
{
    public function getName(): string;

    /**
     * Format-only validation of an Iranian national code (کد ملی):
     * 10 digits, not all identical, checksum valid.
     *
     * @return array{format_valid: bool, verified: bool, sandbox: bool, provider: string, status: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function validateNationalId(string $nationalCode): array;

    /**
     * National-identity verification for the user. A real adapter
     * confirms the code + name + birth date against the civil registry
     * (contract required). The sandbox adapter always returns
     * `requires_manual_review`.
     *
     * @param  array<string, mixed>  $identity  References only (national-code hash / last-4, name). Never the full code.
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function verifyNationalIdentity(int $userId, array $identity): array;

    /**
     * Mobile-number ownership (e.g. Shahkar registry match, or an
     * operator OTP flow). A real adapter proves the number belongs to
     * the national-code holder. The sandbox adapter always returns
     * `requires_manual_review`.
     *
     * @param  array<string, mixed>  $mobile  References only (mobile last-4 / hash). Never the full number.
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function verifyMobileOwnership(int $userId, array $mobile): array;

    /**
     * Bank-account ownership for an Iranian account / IBAN (e.g. a
     * micro-deposit / penny-drop, or a bank's holder confirmation).
     * The sandbox adapter always returns `requires_manual_review`.
     *
     * @param  array<string, mixed>  $account  References only (holder name, last-4, hash). Never full account numbers.
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function verifyBankAccountOwnership(int $userId, array $account): array;

    /**
     * Document authenticity check (national ID card / Shenasnameh /
     * passport). The sandbox adapter always returns
     * `requires_manual_review`.
     *
     * @param  array<string, mixed>  $document  Document references (type, storage path, provider reference). Never document contents.
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function verifyDocument(int $userId, array $document): array;

    /**
     * Hand the case to a human reviewer (KycManualReviewService).
     * Returns the queueing result; it never approves anything itself.
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, reason: ?string, metadata: array<string, mixed>}
     */
    public function requestHumanReview(int $userId, string $reason): array;
}
