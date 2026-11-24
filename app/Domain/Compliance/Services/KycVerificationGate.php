<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Kyc\Exceptions\SimulatedVerificationNotAvailableException;

/**
 * Central gate for simulated KYC verification.
 *
 * The in-house biometric / document / identity services are simulations.
 * This gate is the single place that decides whether they may run at all:
 *
 *  - Production mode (APP_ENV=production, or kyc.verification.mode =
 *    'production') disables them outright, whatever the flag says.
 *  - Outside production they run only when
 *    kyc.verification.simulated_providers_enabled is true AND the app runs
 *    in a sandbox environment (local / testing / demo).
 *  - Even when allowed, simulated results are labelled `sandbox` /
 *    `simulated` and must be routed to manual review — the gate never
 *    certifies a simulated result as a pass.
 *
 * Every simulated service consults this gate before producing a result and
 * returns a fail-closed (not-verified / requires-manual-review) payload
 * when the gate is closed.
 */
final class KycVerificationGate
{
    public function isProduction(): bool
    {
        return app()->environment('production')
            || (string) config('kyc.verification.mode', 'sandbox') === 'production';
    }

    /** Whether simulated providers may be invoked at all in this environment. */
    public function simulatedProvidersAllowed(): bool
    {
        if ($this->isProduction()) {
            return false;
        }

        if (! (bool) config('kyc.verification.simulated_providers_enabled', false)) {
            return false;
        }

        /** @var list<string> $sandboxEnvironments */
        $sandboxEnvironments = (array) config(
            'kyc.verification.sandbox_environments',
            ['local', 'testing', 'demo']
        );

        return in_array(app()->environment(), $sandboxEnvironments, true);
    }

    public function isSandboxAllowed(): bool
    {
        return $this->simulatedProvidersAllowed();
    }

    /** @throws SimulatedVerificationNotAvailableException */
    public function assertSandboxAllowed(string $check): void
    {
        if (! $this->simulatedProvidersAllowed()) {
            throw SimulatedVerificationNotAvailableException::for($check);
        }
    }

    /** Whether a real (non-sandbox) provider has credentials configured. */
    public function isRealProviderConfigured(string $provider): bool
    {
        return match ($provider) {
            'ondato' => filled(config('services.ondato.application_id'))
                && filled(config('services.ondato.kyc_api_url')),
            'bridge'              => filled(config('kyc.providers.bridge.api_key')),
            'sandbox', 'none', '' => false,
            default               => false,
        };
    }

    /** Canonical fail-closed liveness payload. Never a pass. */
    public function failClosedLiveness(): array
    {
        return [
            'is_live'    => false,
            'confidence' => 0.0,
            'checks'     => [
                'face_detected'          => false,
                'multiple_faces'         => false,
                'eyes_visible'           => false,
                'face_centered'          => false,
                'adequate_lighting'      => false,
                'blur_detected'          => false,
                'screen_detected'        => false,
                'mask_detected'          => false,
                'printed_image_detected' => false,
            ],
            'recommendation' => 'Failed - Simulated provider disabled; manual review required',
            'status'         => 'requires_manual_review',
            'verified'       => false,
            'sandbox'        => false,
            'simulated'      => true,
            'provider'       => 'none',
            'reason'         => 'simulated_provider_disabled',
        ];
    }

    /** Canonical fail-closed face-match payload. Never a pass. */
    public function failClosedFaceMatch(): array
    {
        return [
            'faces_detected'   => ['image1' => false, 'image2' => false],
            'face_count'       => ['image1' => 0, 'image2' => 0],
            'similarity'       => null,
            'match_threshold'  => 85.0,
            'is_match'         => false,
            'confidence_level' => 'very_low',
            'status'           => 'requires_manual_review',
            'verified'         => false,
            'sandbox'          => false,
            'simulated'        => true,
            'provider'         => 'none',
            'reason'           => 'simulated_provider_disabled',
        ];
    }

    /** Canonical fail-closed document payload. Contains no extracted data. */
    public function failClosedDocument(string $documentType): array
    {
        return [
            'document_type'         => $documentType,
            'extraction_confidence' => 0.0,
            'extracted_at'          => null,
            'status'                => 'requires_manual_review',
            'verified'              => false,
            'sandbox'               => false,
            'simulated'             => true,
            'provider'              => 'none',
            'reason'                => 'simulated_provider_disabled',
        ];
    }

    /** Canonical fail-closed document-authenticity payload. Never authentic. */
    public function failClosedAuthenticity(): array
    {
        return [
            'is_authentic'     => false,
            'confidence'       => 0.0,
            'checks_performed' => [],
            'fraud_indicators' => null,
            'data_consistency' => false,
            'status'           => 'requires_manual_review',
            'verified'         => false,
            'sandbox'          => false,
            'simulated'        => true,
            'provider'         => 'none',
            'reason'           => 'simulated_provider_disabled',
        ];
    }

    /** Canonical fail-closed identity payload. Never a match. */
    public function failClosedIdentity(): array
    {
        return [
            'match_found'      => false,
            'match_confidence' => 0,
            'sources_checked'  => [],
            'discrepancies'    => [],
            'status'           => 'not_verified',
            'verified'         => false,
            'sandbox'          => false,
            'simulated'        => true,
            'provider'         => 'none',
            'reason'           => 'simulated_provider_disabled',
        ];
    }
}
