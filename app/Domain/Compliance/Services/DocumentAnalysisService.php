<?php

namespace App\Domain\Compliance\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * SIMULATED document analysis — sandbox/demo only.
 *
 * Consults KycVerificationGate before producing any result. In production
 * (or when simulated providers are disabled) extraction returns no data,
 * authenticity returns not-authentic, and comparison returns no match —
 * all with status `requires_manual_review`. In a sandbox environment the
 * simulated values are returned labelled `sandbox` / `simulated` with
 * `verified=false`; they are illustration, never evidence.
 */
class DocumentAnalysisService
{
    private KycVerificationGate $gate;

    public function __construct(?KycVerificationGate $gate = null)
    {
        $this->gate = $gate ?? new KycVerificationGate();
    }

    /** True when this simulated service may run at all in this environment. */
    public function isSandboxAllowed(): bool
    {
        return $this->gate->simulatedProvidersAllowed();
    }

    /**
     * Extract data from identity document.
     *
     * Simulated — and a simulated extractor cannot establish identity:
     * the old version returned a fabricated identity (John Doe, DOB
     * 1990-01-01, random document number, MRZ) for every document type,
     * and EnhancedKycService stored those values on the verification
     * record as if they were the user's real identity. Removed: no
     * personal data is returned at all — only the document type and a
     * zero confidence, labelled sandbox / requires_manual_review.
     */
    public function extractDocumentData(string $documentPath, string $documentType): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            // Fail closed: no fabricated identity data in a production path.
            return $this->gate->failClosedDocument($documentType);
        }

        return array_merge([
            'document_type'         => $documentType,
            'extraction_confidence' => 0.0,
            'extracted_at'          => null,
        ], $this->sandboxLabel());
    }

    /**
     * Extract address data from document.
     *
     * Simulated — the old version returned a fabricated address and
     * account holder ("John Doe", 123 Main Street) for every document
     * type. Removed: no address data is returned; a real address comes
     * from a real provider or a human reviewer reading the document.
     */
    public function extractAddressData(string $documentPath, string $documentType): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            // Fail closed: no fabricated address data in a production path.
            return [
                'document_type' => $documentType,
                'status'        => 'requires_manual_review',
                'verified'      => false,
                'sandbox'       => false,
                'simulated'     => true,
                'provider'      => 'none',
                'reason'        => 'simulated_provider_disabled',
            ];
        }

        return array_merge([
            'document_type' => $documentType,
        ], $this->sandboxLabel());
    }

    /**
     * Verify document authenticity.
     *
     * Simulated — the old version returned `is_authentic: true` with a
     * fabricated 94.2 confidence (every sub-check hardcoded to pass).
     * Removed: a simulated authenticity check never passes, in any
     * environment. Same shape as the gate's fail-closed payload, plus
     * the sandbox label.
     */
    public function verifyAuthenticity(string $documentPath, string $documentType): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            // Fail closed: a simulated authenticity check never passes.
            return $this->gate->failClosedAuthenticity();
        }

        return array_merge([
            'is_authentic'     => false,
            'confidence'       => 0.0,
            'checks_performed' => [],
            'fraud_indicators' => null,
            'data_consistency' => false,
        ], $this->sandboxLabel());
    }

    /**
     * Check document recency.
     */
    public function checkDocumentRecency(array $documentData): array
    {
        $result = [
            'is_recent'       => false,
            'age_in_days'     => null,
            'max_allowed_age' => 90, // 3 months
        ];

        // Find date field based on document type
        $dateField = null;
        if (isset($documentData['bill_date'])) {
            $dateField = $documentData['bill_date'];
        } elseif (isset($documentData['statement_date'])) {
            $dateField = $documentData['statement_date'];
        } elseif (isset($documentData['issue_date'])) {
            $dateField = $documentData['issue_date'];
        }

        if ($dateField) {
            $documentDate = \Carbon\Carbon::parse($dateField);
            $result['age_in_days'] = $documentDate->diffInDays(now());
            $result['is_recent'] = $result['age_in_days'] <= $result['max_allowed_age'];
        }

        return $result;
    }

    /**
     * Validate document format.
     */
    protected function validateDocumentFormat(string $documentType): array
    {
        return [
            'check'   => 'format_validation',
            'passed'  => true,
            'details' => "Document format matches expected {$documentType} format",
        ];
    }

    /**
     * Check security features.
     */
    protected function checkSecurityFeatures(string $documentType): array
    {
        // Simulate security feature detection
        $features = [];

        switch ($documentType) {
            case 'passport':
                $features = ['watermark', 'hologram', 'machine_readable_zone'];
                break;
            case 'driving_license':
                $features = ['hologram', 'uv_features', 'microprint'];
                break;
            case 'national_id':
                $features = ['watermark', 'security_thread', 'raised_print'];
                break;
        }

        return [
            'check'             => 'security_features',
            'passed'            => true,
            'features_detected' => $features,
            'details'           => 'All expected security features detected',
        ];
    }

    /**
     * Check data consistency.
     */
    protected function checkDataConsistency(string $documentType): array
    {
        // Check if extracted data is internally consistent
        return [
            'check'   => 'data_consistency',
            'passed'  => true,
            'details' => 'Document data is internally consistent',
        ];
    }

    /**
     * Assess image quality.
     */
    protected function assessImageQuality(string $documentPath): array
    {
        // In production, analyze image resolution, blur, lighting, etc.
        return [
            'check'          => 'image_quality',
            'passed'         => true,
            'resolution'     => '300dpi',
            'blur_score'     => 0.1,
            'lighting_score' => 0.9,
            'details'        => 'Image quality sufficient for verification',
        ];
    }

    /**
     * Detect tampering.
     */
    protected function detectTampering(string $documentPath): array
    {
        // In production, use image forensics to detect manipulation
        return [
            'check'                 => 'tampering_detection',
            'passed'                => true,
            'manipulation_detected' => false,
            'details'               => 'No signs of digital manipulation detected',
        ];
    }

    /**
     * Store document securely.
     *
     * NOTE: the original implementation used Storage::disk('secure'), but
     * no 'secure' disk is defined in config/filesystems.php, so that call
     * fails outside tests. This resolves the configured KYC disk when it
     * exists and falls back to the 'local' disk (storage/app, not
     * web-accessible) otherwise, always with private visibility. Prefer
     * SecureDocumentStorage for new code; this method is kept for callers
     * that pass a local file path.
     */
    public function storeDocument(string $documentPath, string $userId, string $documentType): string
    {
        $fileName = sprintf(
            'kyc/%s/%s_%s_%s',
            $userId,
            $documentType,
            now()->format('YmdHis'),
            uniqid()
        );

        $disk = (string) config('kyc.verification.document_disk', 'private');
        try {
            Storage::disk($disk);
        } catch (Throwable) {
            $disk = 'local';
        }

        $path = Storage::disk($disk)->putFileAs(
            dirname($fileName),
            $documentPath,
            basename($fileName),
            ['visibility' => 'private']
        );

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Failed to store KYC document.');
        }

        return $path;
    }

    /**
     * Compare document with selfie.
     *
     * Simulated — the old version returned a fabricated match
     * (confidence 87.3, liveness true). Removed: a simulated comparison
     * never matches, in any environment.
     */
    public function compareDocumentWithSelfie(string $documentImagePath, string $selfiePath): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            // Fail closed: no simulated face match in a production path.
            return array_merge([
                'match'      => false,
                'confidence' => 0.0,
                'details'    => [
                    'face_detected_in_document' => false,
                    'face_detected_in_selfie'   => false,
                    'facial_similarity'         => null,
                    'liveness_check'            => false,
                ],
                'reason' => 'simulated_provider_disabled',
            ], $this->sandboxLabel());
        }

        return array_merge([
            'match'      => false,
            'confidence' => 0.0,
            'details'    => [
                'face_detected_in_document' => false,
                'face_detected_in_selfie'   => false,
                'facial_similarity'         => null,
                'liveness_check'            => false,
            ],
        ], $this->sandboxLabel());
    }

    /**
     * Labels every simulated result as sandbox, never verified, and always
     * requiring manual review.
     *
     * @return array{sandbox: bool, simulated: bool, verified: bool, status: string, provider: string}
     */
    private function sandboxLabel(): array
    {
        return [
            'sandbox'   => true,
            'simulated' => true,
            'verified'  => false,
            'status'    => 'requires_manual_review',
            'provider'  => 'sandbox',
        ];
    }
}
