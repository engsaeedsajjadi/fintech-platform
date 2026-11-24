<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Kyc\Contracts\BankAccountOwnershipVerifierInterface;
use App\Domain\Compliance\Kyc\Contracts\IdentityVerificationProviderInterface;
use App\Domain\Compliance\Kyc\Providers\SandboxBankAccountOwnershipVerifier;
use App\Domain\Compliance\Kyc\Providers\SandboxKycProvider;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Models\KycBankAccountVerification;
use App\Domain\Compliance\Models\KycDocument;
use App\Domain\Compliance\Models\KycManualReview;
use App\Domain\Compliance\Models\KycVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The real KYC onboarding workflow, step by step:
 *
 *   registration → phone/email verification → national-ID step →
 *   document upload (secure storage) → liveness (external provider
 *   interface; no provider = pending / manual review, never auto-pass) →
 *   bank-account ownership verification (interface + sandbox only) →
 *   risk assessment + sanctions screening → manual admin review →
 *   tier assignment.
 *
 * No step in this workflow can approve a user on the strength of a
 * simulated result. Liveness / document / bank-ownership steps that only
 * have a sandbox provider behind them land in `requires_manual_review`
 * and wait for KycManualReviewService::approve(). Tier assignment happens
 * only through KycService::verifyKyc(), called from that human approval
 * (or from a real provider webhook — Ondato / Bridge — via their own
 * handlers, not here).
 */
final class KycVerificationWorkflowService
{
    public function __construct(
        private readonly KycService $kycService,
        private readonly EnhancedKycService $enhancedKycService,
        private readonly SecureDocumentStorage $documentStorage,
        private readonly KycManualReviewService $manualReviewService,
        private readonly CustomerRiskService $riskService,
        private readonly KycVerificationGate $gate,
        private readonly ?IdentityVerificationProviderInterface $livenessProvider = null,
        private readonly ?BankAccountOwnershipVerifierInterface $bankAccountVerifier = null,
        private readonly ?KycAmlMonitoringService $amlMonitoringService = null,
        private readonly ?KycStatusMachine $statusMachine = null,
    ) {
    }

    private function statusMachine(): KycStatusMachine
    {
        return $this->statusMachine ?? new KycStatusMachine();
    }

    private function liveness(): IdentityVerificationProviderInterface
    {
        return $this->livenessProvider ?? new SandboxKycProvider($this->gate);
    }

    private function bankVerifier(): BankAccountOwnershipVerifierInterface
    {
        return $this->bankAccountVerifier ?? new SandboxBankAccountOwnershipVerifier($this->gate);
    }

    /**
     * Record a phone/email verification outcome. The $reference (OTP id,
     * provider message id) is NOT stored — only the boolean outcome and
     * timestamp are kept, so no verification secret ever lands in the DB.
     */
    public function recordContactVerification(User $user, string $channel, bool $verified): bool
    {
        if (! in_array($channel, ['email', 'phone'], true)) {
            throw new InvalidArgumentException("Unknown contact channel: {$channel}");
        }

        $kycData = $user->kyc_data ?? [];
        $kycData['contact'][$channel] = [
            'verified'    => $verified,
            'verified_at' => $verified ? now()->toIso8601String() : null,
        ];
        $user->update(['kyc_data' => $kycData]);

        AuditLog::log(
            $verified ? 'kyc.contact_verified' : 'kyc.contact_failed',
            $user,
            null,
            ['channel' => $channel, 'verified' => $verified],
            null,
            'kyc,compliance'
        );

        return $verified;
    }

    public function isContactVerified(User $user, string $channel): bool
    {
        return (bool) ($user->kyc_data['contact'][$channel]['verified'] ?? false);
    }

    /**
     * National-ID step. Only the last 4 digits and a SHA-256 hash of the
     * number are stored — never the full national ID number.
     */
    public function submitNationalId(User $user, array $data): KycVerification
    {
        $number = trim((string) ($data['national_id_number'] ?? ''));
        $country = trim((string) ($data['country_code'] ?? $user->country_code ?? ''));

        if ($number === '' || $country === '') {
            throw new InvalidArgumentException('National ID number and country code are required.');
        }

        return DB::transaction(function () use ($user, $number, $country) {
            $verification = $this->enhancedKycService->startVerification($user, KycVerification::TYPE_IDENTITY, [
                'provider'      => (string) config('kyc.verification.liveness_provider', 'sandbox'),
                'document_type' => 'national_id',
            ]);

            $verification->update([
                'document_country'  => $country,
                'verification_data' => [
                    'national_id' => [
                        'last4'   => substr($number, -4),
                        'hash'    => hash('sha256', $number),
                        'country' => $country,
                    ],
                ],
            ]);

            if (($user->kyc_status ?? 'not_submitted') === 'not_submitted') {
                $this->statusMachine()->submit($user, 'user:' . $user->id);
                $user->update(['kyc_submitted_at' => now()]);
            }

            AuditLog::log(
                'kyc.national_id_submitted',
                $user,
                null,
                ['verification_id' => $verification->getKey(), 'country' => $country],
                null,
                'kyc,compliance'
            );

            return $verification->refresh();
        });
    }

    /** Upload a KYC document to secure private storage. Returns the record. */
    public function uploadDocument(User $user, UploadedFile $file, string $documentType): KycDocument
    {
        return DB::transaction(function () use ($user, $file, $documentType) {
            $stored = $this->documentStorage->store($user, $file, $documentType);

            $document = KycDocument::create([
                'user_uuid'     => $user->uuid,
                'document_type' => $documentType,
                'file_path'     => $stored['path'],
                'file_hash'     => $stored['hash'],
                'uploaded_at'   => now(),
                'metadata'      => [
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type'     => $file->getMimeType(),
                    'size'          => $file->getSize(),
                    'disk'          => $stored['disk'],
                ],
            ]);

            $this->statusMachine()->submit($user, 'user:' . $user->id);
            $user->update(['kyc_submitted_at' => now()]);

            AuditLog::log(
                'kyc.document_uploaded',
                $user,
                null,
                ['document_id' => $document->getKey(), 'document_type' => $documentType, 'sha256' => $stored['hash']],
                null,
                'kyc,compliance'
            );

            return $document;
        });
    }

    /**
     * Liveness step. With no real provider (sandbox / none) the result is
     * always `requires_manual_review` and the verification is queued for a
     * human. A real provider verdict of `approved` is the only way this
     * step passes without manual review — and no real provider implements
     * verifyLiveness() in-repo yet.
     *
     * @return array{status: string, verified: bool, sandbox: bool, provider: string, verification_id: string}
     */
    public function requestLiveness(User $user, ?KycVerification $verification = null): array
    {
        $provider = $this->liveness();
        $verification ??= $this->enhancedKycService->startVerification($user, KycVerification::TYPE_IDENTITY, [
            'provider'      => $provider->getName(),
            'document_type' => 'selfie',
        ]);

        $result = $provider->verifyLiveness($user->id, ['verification_id' => $verification->getKey()]);

        $verification->update([
            'provider'          => $provider->getName(),
            'verification_data' => array_merge($verification->verification_data ?? [], ['liveness' => $result]),
        ]);

        if (($result['verified'] ?? false) === true && ($result['status'] ?? '') === 'approved') {
            $verification->update(['status' => KycVerification::STATUS_IN_PROGRESS]);
        } else {
            $verification->update(['status' => KycVerification::STATUS_REQUIRES_MANUAL_REVIEW]);
            $this->manualReviewService->submitForReview(
                $verification->refresh(),
                'Liveness: ' . ($result['reason'] ?? 'requires manual review')
            );
        }

        return [
            'status'          => (string) $result['status'],
            'verified'        => (bool) ($result['verified'] ?? false),
            'sandbox'         => (bool) ($result['sandbox'] ?? false),
            'provider'        => $provider->getName(),
            'verification_id' => (string) $verification->getKey(),
        ];
    }

    /**
     * Bank-account ownership step. The full account number / IBAN is never
     * persisted — only holder name, last 4, and a SHA-256 hash.
     */
    public function verifyBankAccountOwnership(User $user, array $account): KycBankAccountVerification
    {
        $number = trim((string) ($account['account_number'] ?? $account['iban'] ?? ''));
        $holder = trim((string) ($account['account_holder_name'] ?? $user->name ?? ''));

        if ($number === '' || $holder === '') {
            throw new InvalidArgumentException('Account holder name and account number / IBAN are required.');
        }

        return DB::transaction(function () use ($user, $number, $holder) {
            $record = KycBankAccountVerification::create([
                'user_id'             => $user->id,
                'account_holder_name' => $holder,
                'account_last4'       => substr(preg_replace('/\s+/', '', $number), -4),
                'account_hash'        => hash('sha256', $number),
                'status'              => KycBankAccountVerification::STATUS_PENDING,
                'provider'            => $this->bankVerifier()->getName(),
            ]);

            $result = $this->bankVerifier()->verify($user->id, [
                'account_holder_name' => $holder,
                'account_last4'       => $record->account_last4,
            ]);

            $record->update([
                'status' => ($result['verified'] ?? false) === true && ($result['status'] ?? '') === 'approved'
                    ? KycBankAccountVerification::STATUS_VERIFIED
                    : KycBankAccountVerification::STATUS_REQUIRES_MANUAL_REVIEW,
                'provider_reference' => $result['metadata']['reference'] ?? null,
            ]);

            AuditLog::log(
                'kyc.bank_account_submitted',
                $user,
                null,
                ['bank_verification_id' => $record->getKey(), 'status' => $record->status, 'provider' => $record->provider],
                null,
                'kyc,compliance'
            );

            return $record->refresh();
        });
    }

    /** Risk assessment scoring hook: builds/refreshes the customer risk profile. */
    public function assessRisk(User $user, ?KycVerification $verification = null): \App\Domain\Compliance\Models\CustomerRiskProfile
    {
        $profile = $this->riskService->createOrUpdateProfile($user);

        if ($verification !== null) {
            $verification->update([
                'risk_level'   => $profile->risk_rating,
                'risk_factors' => array_merge($verification->risk_factors ?? [], ['risk_score' => $profile->risk_score]),
            ]);
        }

        return $profile;
    }

    /** Sanctions screening hook (delegates to KycAmlMonitoringService when available). */
    public function screenUser(User $user): ?array
    {
        if ($this->amlMonitoringService === null) {
            return null;
        }

        $result = $this->amlMonitoringService->screenUser($user);

        if ($result['requires_manual_review']) {
            $verification = KycVerification::where('user_id', $user->id)
                ->whereIn('status', [KycVerification::STATUS_PENDING, KycVerification::STATUS_IN_PROGRESS])
                ->latest()
                ->first();

            if ($verification !== null) {
                $this->manualReviewService->submitForReview($verification, 'Sanctions screening match requires manual review');
            }
        }

        return $result;
    }

    /** The next incomplete step for the user, or 'complete' / 'manual_review'. */
    public function currentStep(User $user): string
    {
        if (! $this->isContactVerified($user, 'email') || ! $this->isContactVerified($user, 'phone')) {
            return 'contact_verification';
        }

        $hasNationalId = KycVerification::where('user_id', $user->id)
            ->where('type', KycVerification::TYPE_IDENTITY)
            ->where('document_type', 'national_id')
            ->exists();
        if (! $hasNationalId) {
            return 'national_id';
        }

        if ($user->kycDocuments()->count() === 0) {
            return 'document_upload';
        }

        $inManualReview = KycVerification::where('user_id', $user->id)
            ->where('status', KycVerification::STATUS_REQUIRES_MANUAL_REVIEW)
            ->exists()
            || KycBankAccountVerification::where('user_id', $user->id)
                ->where('status', KycBankAccountVerification::STATUS_REQUIRES_MANUAL_REVIEW)
                ->exists();
        if ($inManualReview) {
            return 'manual_review';
        }

        $hasLiveness = KycVerification::where('user_id', $user->id)
            ->where('document_type', 'selfie')
            ->exists();
        if (! $hasLiveness) {
            return 'liveness';
        }

        $bankVerified = KycBankAccountVerification::where('user_id', $user->id)
            ->where('status', KycBankAccountVerification::STATUS_VERIFIED)
            ->exists();
        if (! $bankVerified) {
            return 'bank_account';
        }

        return ($user->kyc_status === 'approved') ? 'complete' : 'manual_review';
    }

    /**
     * Complete verification history for a user: verifications, manual-review
     * decisions, and the append-only audit log. AuditLog rows are never
     * updated or deleted in place — this is the immutable trail.
     *
     * @return array{verifications: Collection, manual_reviews: Collection, audit_log: Collection}
     */
    public function getVerificationHistory(User $user): array
    {
        return [
            'verifications'  => KycVerification::where('user_id', $user->id)->latest()->get(),
            'manual_reviews' => KycManualReview::where('user_id', $user->id)->latest()->get(),
            'audit_log'      => AuditLog::forUser($user->uuid)->orderBy('created_at')->get(),
        ];
    }
}
