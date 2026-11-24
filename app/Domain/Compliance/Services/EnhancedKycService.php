<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Events\KycVerificationCompleted;
use App\Domain\Compliance\Events\KycVerificationStarted;
use App\Domain\Compliance\Models\CustomerRiskProfile;
use App\Domain\Compliance\Models\KycVerification;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

class EnhancedKycService
{
    private IdentityVerificationService $identityService;

    private DocumentAnalysisService $documentService;

    private BiometricVerificationService $biometricService;

    public function __construct(
        IdentityVerificationService $identityService,
        DocumentAnalysisService $documentService,
        BiometricVerificationService $biometricService
    ) {
        $this->identityService = $identityService;
        $this->documentService = $documentService;
        $this->biometricService = $biometricService;
    }

    /**
     * Start KYC verification process.
     */
    public function startVerification(User $user, string $type, array $data): KycVerification
    {
        return DB::transaction(
            function () use ($user, $type, $data) {
                $verification = KycVerification::create(
                    [
                        'user_id'       => $user->id,
                        'type'          => $type,
                        'status'        => KycVerification::STATUS_PENDING,
                        'provider'      => $data['provider'] ?? 'manual',
                        'document_type' => $data['document_type'] ?? null,
                        'started_at'    => now(),
                    ]
                );

                event(new KycVerificationStarted($verification));

                return $verification;
            }
        );
    }

    /**
     * Verify identity document.
     *
     * Fail-closed rule: when any step result is labelled sandbox / simulated
     * (i.e. it came from the simulated DocumentAnalysisService /
     * IdentityVerificationService), the verification is moved to
     * `requires_manual_review` and this method returns success=false. No
     * sandbox result can complete a verification or approve a user — only
     * a human reviewer (KycManualReviewService) or a real provider verdict
     * can do that.
     */
    public function verifyIdentityDocument(
        KycVerification $verification,
        string $documentPath,
        string $documentType
    ): array {
        try {
            $verification->update(
                [
                    'status'        => KycVerification::STATUS_IN_PROGRESS,
                    'document_type' => $documentType,
                ]
            );

            // Extract document data
            $extractedData = $this->documentService->extractDocumentData($documentPath, $documentType);

            // Verify document authenticity
            $authenticityCheck = $this->documentService->verifyAuthenticity($documentPath, $documentType);

            // Cross-reference with identity databases
            $identityCheck = $this->identityService->verifyIdentity($extractedData);

            // Calculate confidence score
            $confidenceScore = $this->calculateIdentityConfidence($authenticityCheck, $identityCheck);

            $verification->update(
                [
                    'extracted_data'    => $extractedData,
                    'verification_data' => [
                        'authenticity' => $authenticityCheck,
                        'identity'     => $identityCheck,
                    ],
                    'confidence_score' => $confidenceScore,
                    'document_number'  => $extractedData['document_number'] ?? null,
                    'document_country' => $extractedData['issuing_country'] ?? null,
                    'document_expiry'  => $extractedData['expiry_date'] ?? null,
                    'first_name'       => $extractedData['first_name'] ?? null,
                    'last_name'        => $extractedData['last_name'] ?? null,
                    'date_of_birth'    => $extractedData['date_of_birth'] ?? null,
                    'nationality'      => $extractedData['nationality'] ?? null,
                ]
            );

            if ($this->isSandboxResult($extractedData) || $this->isSandboxResult($authenticityCheck) || $this->isSandboxResult($identityCheck)) {
                $verification->update(['status' => KycVerification::STATUS_REQUIRES_MANUAL_REVIEW]);

                return [
                    'success'                => false,
                    'status'                 => KycVerification::STATUS_REQUIRES_MANUAL_REVIEW,
                    'requires_manual_review' => true,
                    'confidence_score'       => $confidenceScore,
                    'extracted_data'         => $extractedData,
                    'reason'                 => 'Document / identity evidence came from a simulated provider; manual review required.',
                ];
            }

            return [
                'success'          => true,
                'confidence_score' => $confidenceScore,
                'extracted_data'   => $extractedData,
            ];
        } catch (Exception $e) {
            Log::error(
                'Identity document verification failed',
                [
                    'verification_id' => $verification->id,
                    'error'           => $e->getMessage(),
                ]
            );

            $verification->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Perform biometric verification.
     *
     * Same fail-closed rule as verifyIdentityDocument(): a sandbox-labelled
     * (or gate-disabled) liveness / face-match result routes the
     * verification to `requires_manual_review` instead of passing.
     */
    public function verifyBiometrics(
        KycVerification $verification,
        string $selfiePath,
        ?string $documentImagePath = null
    ): array {
        try {
            // Perform liveness detection
            $livenessCheck = $this->biometricService->checkLiveness($selfiePath);

            if ($this->isSandboxResult($livenessCheck)) {
                $verification->update(
                    [
                        'status'            => KycVerification::STATUS_REQUIRES_MANUAL_REVIEW,
                        'verification_data' => array_merge(
                            $verification->verification_data ?? [],
                            ['biometric' => ['liveness' => $livenessCheck, 'face_match' => null]]
                        ),
                    ]
                );

                return [
                    'success'                => false,
                    'status'                 => KycVerification::STATUS_REQUIRES_MANUAL_REVIEW,
                    'requires_manual_review' => true,
                    'liveness_score'         => $livenessCheck['confidence'] ?? 0,
                    'face_match_score'       => null,
                    'reason'                 => 'Liveness evidence came from a simulated provider; manual review required.',
                ];
            }

            if (! $livenessCheck['is_live']) {
                throw new Exception('Liveness check failed');
            }

            // Face matching if document image provided
            $faceMatch = null;
            if ($documentImagePath) {
                $faceMatch = $this->biometricService->matchFaces($selfiePath, $documentImagePath);

                if ($this->isSandboxResult($faceMatch)) {
                    $verification->update(
                        [
                            'status'            => KycVerification::STATUS_REQUIRES_MANUAL_REVIEW,
                            'verification_data' => array_merge(
                                $verification->verification_data ?? [],
                                ['biometric' => ['liveness' => $livenessCheck, 'face_match' => $faceMatch]]
                            ),
                        ]
                    );

                    return [
                        'success'                => false,
                        'status'                 => KycVerification::STATUS_REQUIRES_MANUAL_REVIEW,
                        'requires_manual_review' => true,
                        'liveness_score'         => $livenessCheck['confidence'],
                        'face_match_score'       => null,
                        'reason'                 => 'Face-match evidence came from a simulated provider; manual review required.',
                    ];
                }
            }

            $biometricData = [
                'liveness'   => $livenessCheck,
                'face_match' => $faceMatch,
            ];

            $verification->update(
                [
                    'verification_data' => array_merge(
                        $verification->verification_data ?? [],
                        ['biometric' => $biometricData]
                    ),
                ]
            );

            return [
                'success'          => true,
                'liveness_score'   => $livenessCheck['confidence'],
                'face_match_score' => $faceMatch['similarity'] ?? null,
            ];
        } catch (Exception $e) {
            Log::error(
                'Biometric verification failed',
                [
                    'verification_id' => $verification->id,
                    'error'           => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Verify address proof.
     */
    public function verifyAddress(
        KycVerification $verification,
        string $documentPath,
        string $documentType
    ): array {
        try {
            // Extract address from document
            $extractedData = $this->documentService->extractAddressData($documentPath, $documentType);

            // Validate address format and completeness
            $validationResult = $this->validateAddress($extractedData);

            // Verify document recency (e.g., utility bill not older than 3 months)
            $recencyCheck = $this->documentService->checkDocumentRecency($extractedData);

            $verification->update(
                [
                    'address_line1'     => $extractedData['line1'] ?? null,
                    'address_line2'     => $extractedData['line2'] ?? null,
                    'city'              => $extractedData['city'] ?? null,
                    'state'             => $extractedData['state'] ?? null,
                    'postal_code'       => $extractedData['postal_code'] ?? null,
                    'country'           => $extractedData['country'] ?? null,
                    'verification_data' => array_merge(
                        $verification->verification_data ?? [],
                        [
                            'address_validation' => $validationResult,
                            'document_recency'   => $recencyCheck,
                        ]
                    ),
                ]
            );

            if ($this->isSandboxResult($extractedData)) {
                $verification->update(['status' => KycVerification::STATUS_REQUIRES_MANUAL_REVIEW]);

                return [
                    'success'                => false,
                    'status'                 => KycVerification::STATUS_REQUIRES_MANUAL_REVIEW,
                    'requires_manual_review' => true,
                    'address'                => $extractedData,
                    'is_valid'               => $validationResult['is_valid'],
                    'is_recent'              => $recencyCheck['is_recent'],
                    'reason'                 => 'Address evidence came from a simulated provider; manual review required.',
                ];
            }

            return [
                'success'   => true,
                'address'   => $extractedData,
                'is_valid'  => $validationResult['is_valid'],
                'is_recent' => $recencyCheck['is_recent'],
            ];
        } catch (Exception $e) {
            Log::error(
                'Address verification failed',
                [
                    'verification_id' => $verification->id,
                    'error'           => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Complete verification process.
     *
     * Refuses to complete any verification whose evidence is sandbox /
     * simulated, or whose status is `requires_manual_review`. Such a
     * verification can only be completed by KycManualReviewService::approve()
     * after a human decision — this method must never set a user to
     * kyc approved on simulated evidence.
     *
     * @throws LogicException when the verification requires manual review.
     */
    public function completeVerification(KycVerification $verification): void
    {
        if (
            $verification->status === KycVerification::STATUS_REQUIRES_MANUAL_REVIEW
            || $this->containsSandboxFlag($verification->verification_data ?? [])
        ) {
            throw new LogicException(
                'Verification requires manual review and cannot be completed automatically. '
                . 'A human reviewer must approve it via KycManualReviewService.'
            );
        }

        DB::transaction(
            function () use ($verification) {
                // Perform final checks
                $riskAssessment = $this->assessVerificationRisk($verification);

                $verification->update(
                    [
                        'status'       => KycVerification::STATUS_COMPLETED,
                        'completed_at' => now(),
                        'risk_level'   => $riskAssessment['level'],
                        'risk_factors' => $riskAssessment['factors'],
                        'expires_at'   => $this->calculateExpiryDate($riskAssessment['level']),
                    ]
                );

                // Update user KYC status
                $this->updateUserKycStatus($verification);

                // Update or create risk profile
                $this->updateRiskProfile($verification);

                event(new KycVerificationCompleted($verification));
            }
        );
    }

    /**
     * A result is sandbox-sourced when it carries the `simulated` flag
     * (fail-closed payloads included) or the `sandbox` flag. Callers must
     * never treat such a result as verification evidence.
     *
     * @param  array<string, mixed>  $result
     */
    protected function isSandboxResult(array $result): bool
    {
        return ($result['simulated'] ?? false) === true
            || ($result['sandbox'] ?? false) === true;
    }

    /** Recursively checks stored verification data for any sandbox flag. */
    protected function containsSandboxFlag(mixed $data): bool
    {
        if (! is_array($data)) {
            return false;
        }

        foreach ($data as $key => $value) {
            if (($key === 'sandbox' || $key === 'simulated') && $value === true) {
                return true;
            }

            if (is_array($value) && $this->containsSandboxFlag($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate identity confidence score.
     */
    protected function calculateIdentityConfidence(array $authenticityCheck, array $identityCheck): float
    {
        $weights = [
            'document_authentic'  => 0.3,
            'data_consistency'    => 0.2,
            'identity_match'      => 0.3,
            'no_fraud_indicators' => 0.2,
        ];

        $score = 0;

        if ($authenticityCheck['is_authentic'] ?? false) {
            $score += $weights['document_authentic'] * 100;
        }

        if ($authenticityCheck['data_consistency'] ?? false) {
            $score += $weights['data_consistency'] * 100;
        }

        if ($identityCheck['match_found'] ?? false) {
            $score += $weights['identity_match'] * ($identityCheck['match_confidence'] ?? 0);
        }

        if (! ($authenticityCheck['fraud_indicators'] ?? false)) {
            $score += $weights['no_fraud_indicators'] * 100;
        }

        return round($score, 2);
    }

    /**
     * Validate address format and completeness.
     */
    protected function validateAddress(array $address): array
    {
        $required = ['line1', 'city', 'country'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($address[$field])) {
                $missing[] = $field;
            }
        }

        return [
            'is_valid'       => empty($missing),
            'missing_fields' => $missing,
            'completeness'   => (count($required) - count($missing)) / count($required) * 100,
        ];
    }

    /**
     * Assess verification risk.
     */
    protected function assessVerificationRisk(KycVerification $verification): array
    {
        $riskFactors = [];
        $riskScore = 0;

        // Low confidence score
        if ($verification->confidence_score < 70) {
            $riskFactors[] = 'low_confidence_score';
            $riskScore += 20;
        }

        // Document issues
        if ($verification->document_expiry && $verification->document_expiry->isPast()) {
            $riskFactors[] = 'expired_document';
            $riskScore += 15;
        }

        // PEP/Sanctions/Adverse Media
        if ($verification->pep_check) {
            $riskFactors[] = 'pep';
            $riskScore += 30;
        }

        if ($verification->sanctions_check) {
            $riskFactors[] = 'sanctions_hit';
            $riskScore += 50;
        }

        if ($verification->adverse_media_check) {
            $riskFactors[] = 'adverse_media';
            $riskScore += 25;
        }

        // Determine risk level
        $level = match (true) {
            $riskScore >= 50 => KycVerification::RISK_LEVEL_HIGH,
            $riskScore >= 20 => KycVerification::RISK_LEVEL_MEDIUM,
            default          => KycVerification::RISK_LEVEL_LOW,
        };

        return [
            'level'   => $level,
            'score'   => $riskScore,
            'factors' => $riskFactors,
        ];
    }

    /**
     * Calculate verification expiry date.
     */
    protected function calculateExpiryDate(string $riskLevel): \Carbon\Carbon
    {
        return match ($riskLevel) {
            KycVerification::RISK_LEVEL_HIGH   => now()->addMonths(6),
            KycVerification::RISK_LEVEL_MEDIUM => now()->addYear(),
            default                            => now()->addYears(2),
        };
    }

    /**
     * Update user KYC status.
     */
    protected function updateUserKycStatus(KycVerification $verification): void
    {
        $user = $verification->user;

        // Determine KYC level based on verification types completed
        $completedTypes = KycVerification::where('user_id', $user->id)
            ->where('status', KycVerification::STATUS_COMPLETED)
            ->where('expires_at', '>', now())
            ->pluck('type')
            ->unique()
            ->toArray();

        $kycLevel = $this->determineKycLevel($completedTypes);

        $user->update(
            [
                'kyc_status'      => 'approved',
                'kyc_level'       => $kycLevel,
                'kyc_approved_at' => now(),
                'kyc_expires_at'  => $verification->expires_at,
                'risk_rating'     => $verification->risk_level,
            ]
        );
    }

    /**
     * Determine KYC level from completed verifications.
     */
    protected function determineKycLevel(array $completedTypes): string
    {
        if (in_array(KycVerification::TYPE_ENHANCED_DUE_DILIGENCE, $completedTypes)) {
            return 'full';
        }

        if (
            in_array(KycVerification::TYPE_INCOME, $completedTypes)
            && in_array(KycVerification::TYPE_ADDRESS, $completedTypes)
        ) {
            return 'enhanced';
        }

        if (in_array(KycVerification::TYPE_IDENTITY, $completedTypes)) {
            return 'basic';
        }

        return 'none';
    }

    /**
     * Update customer risk profile.
     */
    protected function updateRiskProfile(KycVerification $verification): void
    {
        $profile = CustomerRiskProfile::firstOrCreate(
            ['user_id' => $verification->user_id],
            [
                'risk_rating'               => CustomerRiskProfile::RISK_RATING_LOW,
                'risk_score'                => 0,
                'cdd_level'                 => CustomerRiskProfile::CDD_LEVEL_STANDARD,
                'daily_transaction_limit'   => 10000,
                'monthly_transaction_limit' => 100000,
                'single_transaction_limit'  => 5000,
            ]
        );

        // Update profile based on verification results
        $profile->update(
            [
                'last_assessment_at'       => now(),
                'next_review_at'           => $verification->expires_at,
                'is_pep'                   => $verification->pep_check,
                'pep_verified_at'          => $verification->pep_check ? now() : null,
                'is_sanctioned'            => $verification->sanctions_check,
                'sanctions_verified_at'    => now(),
                'has_adverse_media'        => $verification->adverse_media_check,
                'adverse_media_checked_at' => now(),
            ]
        );

        // Recalculate risk
        $profile->updateRiskAssessment();
    }
}
