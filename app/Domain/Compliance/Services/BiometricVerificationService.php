<?php

namespace App\Domain\Compliance\Services;

/**
 * SIMULATED biometric verification — sandbox/demo only.
 *
 * Every method in this class consults KycVerificationGate first. In
 * production (or when simulated providers are disabled) all verdict
 * methods fail closed: liveness false, no face match, no template. Even
 * in a sandbox environment results are labelled `sandbox` / `simulated`,
 * carry `verified=false` and status `requires_manual_review`, and must be
 * routed to manual review — they are never a verification verdict.
 * Raw biometrics are never stored: no template id is issued in production.
 */
class BiometricVerificationService
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
     * Check liveness of selfie.
     */
    public function checkLiveness(string $imagePath): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            // Fail closed: no simulated liveness result in a production path.
            return $this->gate->failClosedLiveness();
        }

        // In production, this would use AI/ML models to detect:
        // - Eye movement
        // - Facial micro-expressions
        // - 3D depth
        // - Skin texture
        // - Light reflection patterns

        // Simulated liveness check
        $checks = [
            'face_detected'          => true,
            'multiple_faces'         => false,
            'eyes_visible'           => true,
            'face_centered'          => true,
            'adequate_lighting'      => true,
            'blur_detected'          => false,
            'screen_detected'        => false,
            'mask_detected'          => false,
            'printed_image_detected' => false,
        ];

        // Calculate confidence based on checks
        $passedChecks = array_filter($checks, fn ($check) => $check === true);
        $failedChecks = array_filter($checks, fn ($check) => $check === false);

        // Ensure critical checks pass
        $criticalChecks = ['face_detected', 'screen_detected', 'printed_image_detected'];
        $criticalPassed = true;

        foreach ($criticalChecks as $critical) {
            if (isset($checks[$critical])) {
                if ($critical === 'face_detected' && ! $checks[$critical]) {
                    $criticalPassed = false;
                }
                if (in_array($critical, ['screen_detected', 'printed_image_detected']) && $checks[$critical]) {
                    $criticalPassed = false;
                }
            }
        }

        $confidence = $criticalPassed ? (count($passedChecks) / count($checks)) * 100 : 0;

        return array_merge([
            'is_live'        => $confidence >= 80,
            'confidence'     => round($confidence, 2),
            'checks'         => $checks,
            'recommendation' => $this->getLivenessRecommendation($confidence),
        ], $this->sandboxLabel());
    }

    /**
     * Match faces between two images.
     *
     * Simulated — the old version returned a fabricated match
     * (similarity 92.5, `is_match: true`, `confidence_level: high`) in
     * the sandbox. A simulated face match must never pass, in any
     * environment: same shape as the gate's fail-closed payload, plus
     * the sandbox label.
     */
    public function matchFaces(string $image1Path, string $image2Path): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            // Fail closed: no simulated face match in a production path.
            return $this->gate->failClosedFaceMatch();
        }

        return array_merge([
            'faces_detected'   => ['image1' => false, 'image2' => false],
            'face_count'       => ['image1' => 0, 'image2' => 0],
            'similarity'       => null,
            'match_threshold'  => 85.0,
            'is_match'         => false,
            'confidence_level' => 'very_low',
        ], $this->sandboxLabel());
    }

    /**
     * Perform age estimation.
     */
    public function estimateAge(string $imagePath): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            return array_merge([
                'estimated_age' => null,
                'age_range'     => ['min' => null, 'max' => null],
                'confidence'    => 0.0,
            ], $this->failClosedLabel());
        }

        // In production, use age estimation models
        // Simulated age estimation

        return array_merge([
            'estimated_age' => rand(25, 45),
            'age_range'     => [
                'min' => rand(20, 25),
                'max' => rand(45, 50),
            ],
            'confidence' => rand(80, 95) / 100,
        ], $this->sandboxLabel());
    }

    /**
     * Detect facial attributes.
     */
    public function detectAttributes(string $imagePath): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            return array_merge(['attributes' => []], $this->failClosedLabel());
        }

        // In production, detect various facial attributes
        // Simulated attribute detection

        return array_merge([
            'gender' => [
                'value'      => 'male',
                'confidence' => 0.95,
            ],
            'glasses' => [
                'wearing'    => false,
                'confidence' => 0.98,
            ],
            'facial_hair' => [
                'beard'      => false,
                'mustache'   => false,
                'confidence' => 0.92,
            ],
            'emotions' => [
                'neutral'   => 0.85,
                'happy'     => 0.10,
                'sad'       => 0.02,
                'angry'     => 0.01,
                'surprised' => 0.02,
            ],
            'head_pose' => [
                'pitch' => 0.5,
                'roll'  => -1.2,
                'yaw'   => 2.1,
            ],
        ], $this->sandboxLabel());
    }

    /**
     * Create face template for future matching.
     *
     * Raw biometrics are never stored by the platform. In production this
     * simulated template creation is disabled entirely: no template id is
     * issued, so nothing can be stored or matched against later.
     */
    public function createFaceTemplate(string $imagePath): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            return array_merge([
                'template_id'   => null,
                'created_at'    => null,
                'algorithm'     => null,
                'version'       => null,
                'size'          => 0,
                'quality_score' => 0.0,
                'reason'        => 'simulated_provider_disabled',
            ], $this->failClosedLabel());
        }

        // In production, create biometric template
        // Simulated template creation

        return array_merge([
            'template_id'   => 'face_' . uniqid(),
            'created_at'    => now()->toIso8601String(),
            'algorithm'     => 'FaceNet',
            'version'       => '2.0',
            'size'          => 512, // Feature vector size
            'quality_score' => rand(85, 98) / 100,
        ], $this->sandboxLabel());
    }

    /**
     * Compare against face template.
     *
     * Simulated — the old version returned a fabricated match
     * (`is_match: true`, random similarity). A simulated comparison
     * never matches, in any environment.
     */
    public function compareWithTemplate(string $imagePath, string $templateId): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            return array_merge([
                'template_id'     => $templateId,
                'similarity'      => null,
                'is_match'        => false,
                'comparison_time' => null,
                'reason'          => 'simulated_provider_disabled',
            ], $this->failClosedLabel());
        }

        return array_merge([
            'template_id'     => $templateId,
            'similarity'      => null,
            'is_match'        => false,
            'comparison_time' => null,
        ], $this->sandboxLabel());
    }

    /**
     * Detect presentation attacks (spoofing).
     */
    public function detectSpoofing(string $imagePath): array
    {
        // In production, use anti-spoofing models to detect:
        // - Printed photos
        // - Digital screens
        // - 3D masks
        // - Video replays

        $spoofingChecks = [
            'texture_analysis' => [
                'score'   => 0.92,
                'is_real' => true,
            ],
            'color_analysis' => [
                'score'   => 0.88,
                'is_real' => true,
            ],
            'depth_analysis' => [
                'score'   => 0.85,
                'is_real' => true,
            ],
            'reflection_analysis' => [
                'score'   => 0.90,
                'is_real' => true,
            ],
        ];

        $overallScore = array_reduce(
            $spoofingChecks,
            fn ($carry, $check) => $carry + $check['score'],
            0
        ) / count($spoofingChecks);

        if (! $this->gate->simulatedProvidersAllowed()) {
            return array_merge([
                'is_genuine'  => false,
                'confidence'  => 0.0,
                'attack_type' => 'unknown',
                'checks'      => [],
                'reason'      => 'simulated_provider_disabled',
            ], $this->failClosedLabel());
        }

        return array_merge([
            'is_genuine'  => $overallScore >= 0.80,
            'confidence'  => round($overallScore, 2),
            'attack_type' => null,
            'checks'      => $spoofingChecks,
        ], $this->sandboxLabel());
    }

    /**
     * Get liveness recommendation based on confidence.
     */
    protected function getLivenessRecommendation(float $confidence): string
    {
        if ($confidence >= 95) {
            return 'Highly confident - Proceed with verification';
        } elseif ($confidence >= 80) {
            return 'Confident - May proceed with additional checks';
        } elseif ($confidence >= 60) {
            return 'Low confidence - Request new selfie with better conditions';
        } else {
            return 'Failed - Possible spoofing attempt detected';
        }
    }

    /**
     * Get confidence level description.
     */
    protected function getConfidenceLevel(float $similarity): string
    {
        if ($similarity >= 95) {
            return 'very_high';
        } elseif ($similarity >= 90) {
            return 'high';
        } elseif ($similarity >= 85) {
            return 'medium';
        } elseif ($similarity >= 80) {
            return 'low';
        } else {
            return 'very_low';
        }
    }

    /**
     * Validate image quality for biometric processing.
     */
    public function validateImageQuality(string $imagePath): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            return array_merge([
                'is_suitable'     => false,
                'issues'          => ['simulated_provider_disabled'],
                'recommendations' => ['Manual review required'],
                'quality_metrics' => [],
                'reason'          => 'simulated_provider_disabled',
            ], $this->failClosedLabel());
        }

        // Check image properties for biometric suitability
        return array_merge([
            'is_suitable'     => true,
            'issues'          => [],
            'recommendations' => [],
            'quality_metrics' => [
                'resolution' => [
                    'width'         => 1920,
                    'height'        => 1080,
                    'is_sufficient' => true,
                ],
                'face_size' => [
                    'percentage' => 35,
                    'is_optimal' => true,
                ],
                'lighting' => [
                    'score'       => 0.85,
                    'is_adequate' => true,
                ],
                'blur' => [
                    'score'         => 0.1,
                    'is_acceptable' => true,
                ],
                'occlusion' => [
                    'eyes'  => false,
                    'mouth' => false,
                    'nose'  => false,
                ],
            ],
        ], $this->sandboxLabel());
    }

    /**
     * Labels every simulated result as sandbox, never verified, and always
     * requiring manual review. Callers must check `sandbox` / `verified`
     * before treating any value here as evidence.
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

    /** Label for fail-closed results: not sandbox output, just disabled. */
    private function failClosedLabel(): array
    {
        return [
            'sandbox'   => false,
            'simulated' => true,
            'verified'  => false,
            'status'    => 'requires_manual_review',
            'provider'  => 'none',
            'reason'    => 'simulated_provider_disabled',
        ];
    }
}
