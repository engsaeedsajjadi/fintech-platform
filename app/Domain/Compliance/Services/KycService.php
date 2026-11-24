<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Models\KycDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class KycService
{
    private KycStatusMachine $statusMachine;

    public function __construct(?KycStatusMachine $statusMachine = null)
    {
        $this->statusMachine = $statusMachine ?? new KycStatusMachine();
    }

    /**
     * Submit KYC documents for a user.
     *
     * Moves the user to `pending` through KycStatusMachine (illegal from
     * `approved`: an approved user cannot silently resubmit to pending).
     */
    public function submitKyc(User $user, array $documents): void
    {
        DB::transaction(
            function () use ($user, $documents) {
                // Update user KYC status (audited state transition).
                $this->statusMachine->submit($user, 'user:' . $user->id);
                $user->update(['kyc_submitted_at' => now()]);

                // Store documents
                foreach ($documents as $document) {
                    $this->storeDocument($user, $document);
                }

                // Log the action
                AuditLog::log(
                    'kyc.submitted',
                    $user,
                    null,
                    ['documents'      => count($documents)],
                    ['document_types' => array_column($documents, 'type')],
                    'kyc,compliance'
                );
            }
        );
    }

    /**
     * Store a KYC document.
     *
     * Delegates to SecureDocumentStorage: private visibility, SHA-256 hash,
     * and a fallback to the 'local' disk when the configured KYC disk is
     * not defined (no 'private' disk exists in config/filesystems.php).
     */
    protected function storeDocument(User $user, array $documentData): KycDocument
    {
        $stored = app(SecureDocumentStorage::class)->store($user, $documentData['file'], $documentData['type']);

        return KycDocument::create(
            [
                'user_uuid'     => $user->uuid,
                'document_type' => $documentData['type'],
                'file_path'     => $stored['path'],
                'file_hash'     => $stored['hash'],
                'uploaded_at'   => now(),
                'metadata'      => [
                    'original_name' => $documentData['file']->getClientOriginalName(),
                    'mime_type'     => $documentData['file']->getMimeType(),
                    'size'          => $documentData['file']->getSize(),
                    'disk'          => $stored['disk'],
                ],
            ]
        );
    }

    /**
     * Verify user KYC.
     *
     * Approval goes through KycStatusMachine: the caller must name a real
     * basis — `provider_verdict` (a real provider's confirmed verdict) or
     * `manual_review` (a human decision). The default is `manual_review`
     * for the existing admin / manual-review callers; a simulated result
     * is never an accepted basis and approval from a state other than
     * `pending` (or `not_started` for an async provider verdict) throws
     * instead of silently approving.
     */
    public function verifyKyc(User $user, string $verifiedBy, array $options = []): void
    {
        DB::transaction(
            function () use ($user, $verifiedBy, $options) {
                $oldStatus = $user->kyc_status;

                $this->statusMachine->approve(
                    $user,
                    $verifiedBy,
                    $options['basis'] ?? KycStatusMachine::BASIS_MANUAL_REVIEW,
                    ['level' => $options['level'] ?? 'enhanced']
                );

                // Update user status
                $kycLevel = $options['level'] ?? 'enhanced';
                // Slice 4 (F-22): set kyc_completed_at when kyc_level transitions to 'full'.
                // This enables the efficient DispatchKycRequiredCues LEFT JOIN query.
                $kycCompletedAt = $kycLevel === 'full' ? now() : null;

                $user->update(
                    [
                        'kyc_approved_at'  => now(),
                        'kyc_expires_at'   => $options['expires_at'] ?? now()->addYears(2),
                        'kyc_level'        => $kycLevel,
                        'risk_rating'      => $options['risk_rating'] ?? 'low',
                        'pep_status'       => $options['pep_status'] ?? false,
                        'kyc_completed_at' => $kycCompletedAt,
                    ]
                );

                // Mark all pending documents as verified
                $user->kycDocuments()
                    ->pending()
                    ->each(
                        function ($document) use ($verifiedBy, $options) {
                            $document->markAsVerified($verifiedBy, $options['document_expires_at'] ?? null);
                        }
                    );

                // Log the verification
                AuditLog::log(
                    'kyc.approved',
                    $user,
                    ['kyc_status'  => $oldStatus],
                    ['kyc_status'  => 'approved', 'kyc_level' => $user->kyc_level],
                    ['verified_by' => $verifiedBy, 'options' => $options],
                    'kyc,compliance,verification'
                );
            }
        );
    }

    /**
     * Reject user KYC.
     *
     * Rejection goes through KycStatusMachine (audited transition; a
     * rejection may follow an approval — it takes access away — but never
     * from an already-rejected or expired user).
     */
    public function rejectKyc(User $user, string $rejectedBy, string $reason): void
    {
        DB::transaction(
            function () use ($user, $rejectedBy, $reason) {
                $oldStatus = $user->kyc_status;

                $this->statusMachine->reject($user, $rejectedBy, $reason);
                $user->update(['kyc_rejected_at' => now()]);

                // Mark documents as rejected
                $user->kycDocuments()
                    ->pending()
                    ->each(
                        function ($document) use ($reason, $rejectedBy) {
                            $document->markAsRejected($reason, $rejectedBy);
                        }
                    );

                // Log the rejection
                AuditLog::log(
                    'kyc.rejected',
                    $user,
                    ['kyc_status'  => $oldStatus],
                    ['kyc_status'  => 'rejected'],
                    ['rejected_by' => $rejectedBy, 'reason' => $reason],
                    'kyc,compliance,rejection'
                );
            }
        );
    }

    /**
     * Expire a user's KYC through the state machine (audited transition).
     * Used by provider webhooks when a verification expires while the
     * user is still pending, and by checkExpiredKyc() below.
     */
    public function expireKyc(User $user, string $actor = 'system'): void
    {
        DB::transaction(function () use ($user, $actor) {
            $oldStatus = $user->kyc_status;

            $this->statusMachine->expire($user, $actor);

            AuditLog::log(
                'kyc.expired',
                $user,
                ['kyc_status' => $oldStatus],
                ['kyc_status' => 'expired'],
                ['actor'      => $actor],
                'kyc,compliance,expired'
            );
        });
    }

    /**
     * Check if KYC is expired and update status.
     */
    public function checkExpiredKyc(User $user): bool
    {
        if ($user->kyc_status === 'approved' && $user->kyc_expires_at && $user->kyc_expires_at->isPast()) {
            $this->expireKyc($user);

            return true;
        }

        return false;
    }

    /**
     * Get KYC requirements for a specific level.
     */
    public function getRequirements(string $level): array
    {
        return match ($level) {
            'basic' => [
                'documents' => ['national_id', 'selfie'],
                'limits'    => [
                    'daily_transaction'   => 100000, // $1,000
                    'monthly_transaction' => 500000, // $5,000
                    'max_balance'         => 1000000, // $10,000
                ],
            ],
            'enhanced' => [
                'documents' => ['passport', 'utility_bill', 'selfie'],
                'limits'    => [
                    'daily_transaction'   => 1000000, // $10,000
                    'monthly_transaction' => 5000000, // $50,000
                    'max_balance'         => 10000000, // $100,000
                ],
            ],
            'full' => [
                'documents' => ['passport', 'utility_bill', 'bank_statement', 'selfie', 'proof_of_income'],
                'limits'    => [
                    'daily_transaction'   => null, // No limit
                    'monthly_transaction' => null, // No limit
                    'max_balance'         => null, // No limit
                ],
            ],
            default => throw new InvalidArgumentException("Unknown KYC level: {$level}"),
        };
    }

    /**
     * Get KYC status for a user.
     */
    public function getKycStatus(User $user): string
    {
        // Check if KYC is expired
        $this->checkExpiredKyc($user);

        return $user->kyc_status ?? 'not_submitted';
    }

    /**
     * Check if user's KYC is approved.
     */
    public function isKycApproved(User $user): bool
    {
        return $this->getKycStatus($user) === 'approved';
    }
}
