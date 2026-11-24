<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Kyc\Exceptions\SimulatedVerificationNotAvailableException;
use InvalidArgumentException;

/**
 * SIMULATED identity verification for Jumio / Onfido / SmileID — sandbox/demo only.
 *
 * The Jumio / Onfido / SmileID branches have no credentials and no HTTP
 * calls; they return fabricated sessions and results. KycVerificationGate
 * now guards them: in production (or when simulated providers are
 * disabled) verifyIdentity() fails closed (no match) and session/result
 * calls for those providers throw
 * SimulatedVerificationNotAvailableException. In a sandbox environment
 * the simulated values are returned labelled `sandbox` / `simulated`
 * with `verified=false` and status `requires_manual_review`.
 *
 * The Ondato branch is different: it delegates to OndatoService, which
 * performs real HTTP calls. Its old fallback — a fabricated session when
 * no User object was supplied — has been removed: it now throws instead
 * of inventing a session.
 */
class IdentityVerificationService
{
    private KycVerificationGate $gate;

    private array $providers = [
        'jumio' => [
            'endpoint' => 'https://api.jumio.com/v1/',
            'api_key'  => null,
        ],
        'onfido' => [
            'endpoint' => 'https://api.onfido.com/v3/',
            'api_key'  => null,
        ],
        'smileid' => [
            'endpoint'      => 'https://api.smileidentity.com/v1/',
            'api_key'       => null,
            'partner_id'    => null,
            'signature_key' => null,
        ],
        'ondato' => [
            'kyc_api_url'    => null,
            'application_id' => null,
        ],
    ];

    public function __construct(?KycVerificationGate $gate = null)
    {
        $this->gate = $gate ?? new KycVerificationGate();
        $this->providers['jumio']['api_key'] = config('services.jumio.api_key');
        $this->providers['onfido']['api_key'] = config('services.onfido.api_key');
        $this->providers['smileid']['api_key'] = config('services.smileid.api_key');
        $this->providers['smileid']['partner_id'] = config('services.smileid.partner_id');
        $this->providers['smileid']['signature_key'] = config('services.smileid.signature_key');
        $this->providers['ondato']['kyc_api_url'] = config('services.ondato.kyc_api_url');
        $this->providers['ondato']['application_id'] = config('services.ondato.application_id');
    }

    /**
     * Verify identity against external databases.
     */
    public function verifyIdentity(array $data): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            // Fail closed: no simulated identity match in a production path.
            return $this->gate->failClosedIdentity();
        }

        // In production, this would make actual API calls to identity verification services
        // For demonstration, simulate the verification

        $results = [
            'match_found'      => false,
            'match_confidence' => 0,
            'sources_checked'  => [],
            'discrepancies'    => [],
            'sandbox'          => true,
            'simulated'        => true,
            'verified'         => false,
            'status'           => 'requires_manual_review',
            'provider'         => 'sandbox',
        ];

        // Simulate identity database check
        $identityCheck = $this->checkIdentityDatabase($data);
        $results['sources_checked'][] = 'National Identity Database';

        if ($identityCheck['found']) {
            $results['match_found'] = true;
            $results['match_confidence'] = $identityCheck['confidence'];

            // Check for discrepancies
            $discrepancies = $this->findDiscrepancies($data, $identityCheck['data']);
            if (! empty($discrepancies)) {
                $results['discrepancies'] = $discrepancies;
                $results['match_confidence'] -= count($discrepancies) * 10;
            }
        }

        // Check against credit bureaus
        $creditCheck = $this->checkCreditBureaus($data);
        $results['sources_checked'][] = 'Credit Bureaus';

        if ($creditCheck['found'] && ! $results['match_found']) {
            $results['match_found'] = true;
            $results['match_confidence'] = $creditCheck['confidence'] * 0.8; // Lower weight for credit bureau
        }

        return $results;
    }

    /**
     * Create verification session with provider.
     *
     * @throws SimulatedVerificationNotAvailableException for the simulated
     *         providers (jumio / onfido / smileid) outside a sandbox env.
     */
    public function createVerificationSession(string $provider, array $userData): array
    {
        if (in_array($provider, ['jumio', 'onfido', 'smileid'], true)) {
            $this->gate->assertSandboxAllowed("{$provider} verification session");
        }

        switch ($provider) {
            case 'jumio':
                return $this->createJumioSession($userData);
            case 'onfido':
                return $this->createOnfidoSession($userData);
            case 'smileid':
                return $this->createSmileIdSession($userData);
            case 'ondato':
                return $this->createOndatoSession($userData);
            default:
                throw new InvalidArgumentException("Unknown provider: {$provider}");
        }
    }

    /**
     * Get verification result from provider.
     *
     * @throws SimulatedVerificationNotAvailableException for the simulated
     *         providers (jumio / onfido / smileid) outside a sandbox env.
     */
    public function getVerificationResult(string $provider, string $sessionId): array
    {
        if (in_array($provider, ['jumio', 'onfido', 'smileid'], true)) {
            $this->gate->assertSandboxAllowed("{$provider} verification result");
        }

        switch ($provider) {
            case 'jumio':
                return $this->getJumioResult($sessionId);
            case 'onfido':
                return $this->getOnfidoResult($sessionId);
            case 'smileid':
                return $this->getSmileIdResult($sessionId);
            case 'ondato':
                return $this->getOndatoResult($sessionId);
            default:
                throw new InvalidArgumentException("Unknown provider: {$provider}");
        }
    }

    /**
     * Check identity database (simulated).
     */
    protected function checkIdentityDatabase(array $data): array
    {
        // Simulate database lookup
        $firstName = strtolower($data['first_name'] ?? '');
        $lastName = strtolower($data['last_name'] ?? '');
        $dob = $data['date_of_birth'] ?? '';

        // Simulate positive match for testing
        if ($firstName === 'john' && $lastName === 'doe') {
            return [
                'found'      => true,
                'confidence' => 95,
                'data'       => [
                    'first_name'    => 'John',
                    'last_name'     => 'Doe',
                    'date_of_birth' => $dob,
                    'nationality'   => 'US',
                    'id_number'     => 'ID123456789',
                ],
            ];
        }

        return ['found' => false];
    }

    /**
     * Check credit bureaus (simulated).
     */
    protected function checkCreditBureaus(array $data): array
    {
        // Simulate credit bureau check
        return [
            'found'      => rand(0, 10) > 3, // 70% chance of finding record
            'confidence' => rand(70, 90),
        ];
    }

    /**
     * Find discrepancies between provided and verified data.
     */
    protected function findDiscrepancies(array $provided, array $verified): array
    {
        $discrepancies = [];
        $fields = ['first_name', 'last_name', 'date_of_birth', 'nationality'];

        foreach ($fields as $field) {
            if (isset($provided[$field]) && isset($verified[$field])) {
                if (strtolower($provided[$field]) !== strtolower($verified[$field])) {
                    $discrepancies[] = [
                        'field'    => $field,
                        'provided' => $provided[$field],
                        'verified' => $verified[$field],
                    ];
                }
            }
        }

        return $discrepancies;
    }

    /**
     * Create Jumio verification session.
     */
    protected function createJumioSession(array $userData): array
    {
        // In production, this would make actual API call
        // Simulated response
        return array_merge([
            'session_id'   => 'jumio_' . uniqid(),
            'redirect_url' => 'https://example.jumio.com/verify/' . uniqid(),
            'expires_at'   => now()->addHours(24)->toIso8601String(),
        ], $this->sandboxLabel());
    }

    /**
     * Create Onfido verification session.
     */
    protected function createOnfidoSession(array $userData): array
    {
        // In production, this would make actual API call
        // Simulated response
        return array_merge([
            'session_id'   => 'onfido_' . uniqid(),
            'sdk_token'    => 'sdk_' . uniqid(),
            'applicant_id' => 'app_' . uniqid(),
            'expires_at'   => now()->addHours(24)->toIso8601String(),
        ], $this->sandboxLabel());
    }

    /**
     * Get Jumio verification result.
     */
    protected function getJumioResult(string $sessionId): array
    {
        // In production, this would make actual API call
        // Simulated response
        return array_merge([
            'status'     => 'completed',
            'result'     => 'passed',
            'confidence' => 92.5,
            'checks'     => [
                'document_validity' => true,
                'face_match'        => true,
                'data_extraction'   => true,
                'fraud_check'       => true,
            ],
            'extracted_data' => [
                'first_name'      => 'John',
                'last_name'       => 'Doe',
                'date_of_birth'   => '1990-01-01',
                'document_number' => 'P123456789',
                'document_type'   => 'passport',
                'issuing_country' => 'US',
                'expiry_date'     => '2025-12-31',
            ],
        ], $this->sandboxLabel());
    }

    /**
     * Get Onfido verification result.
     */
    protected function getOnfidoResult(string $sessionId): array
    {
        // In production, this would make actual API call
        // Simulated response
        return array_merge([
            'status'     => 'complete',
            'result'     => 'clear',
            'sub_result' => 'clear',
            'reports'    => [
                [
                    'name'      => 'document',
                    'result'    => 'clear',
                    'breakdown' => [
                        'data_extraction'     => 'clear',
                        'data_validation'     => 'clear',
                        'image_integrity'     => 'clear',
                        'visual_authenticity' => 'clear',
                    ],
                ],
                [
                    'name'      => 'facial_similarity_photo',
                    'result'    => 'clear',
                    'breakdown' => [
                        'face_match'      => 'clear',
                        'image_integrity' => 'clear',
                    ],
                ],
            ],
        ], $this->sandboxLabel());
    }

    /**
     * Create Smile ID verification session.
     *
     * @param  array<string, mixed>  $userData
     * @return array<string, mixed>
     */
    protected function createSmileIdSession(array $userData): array
    {
        // In production, this would make actual API call to Smile ID
        // Simulated response
        $countryCode = $userData['country_code'] ?? 'NG';
        $idType = $userData['id_type'] ?? 'NATIONAL_ID';

        return array_merge([
            'session_id'   => 'smileid_' . uniqid(),
            'upload_url'   => 'https://upload.smileidentity.com/v1/' . uniqid(),
            'country_code' => $countryCode,
            'id_type'      => $idType,
            'job_type'     => 5, // Enhanced Document Verification
            'expires_at'   => now()->addHours(24)->toIso8601String(),
        ], $this->sandboxLabel());
    }

    /**
     * Get Smile ID verification result.
     *
     * @return array<string, mixed>
     */
    protected function getSmileIdResult(string $sessionId): array
    {
        // In production, this would make actual API call to Smile ID
        // Simulated response
        return array_merge([
            'status'     => 'completed',
            'confidence' => 99.5,
            'checks'     => [
                'document_validity'  => true,
                'face_match'         => true,
                'liveness_check'     => true,
                'id_authority_check' => true,
            ],
            'extracted_data' => [
                'first_name'      => 'Adebayo',
                'last_name'       => 'Ogunlesi',
                'date_of_birth'   => '1985-03-15',
                'document_number' => 'A00000000',
                'document_type'   => 'national_id',
                'issuing_country' => 'NG',
                'expiry_date'     => '2030-12-31',
            ],
            'smile_job_id' => 'sjid_' . uniqid(),
            'result_code'  => '1012',
        ], $this->sandboxLabel());
    }

    /**
     * Create Ondato verification session.
     *
     * Delegates to OndatoService for real API integration.
     *
     * @param  array<string, mixed>  $userData
     * @return array<string, mixed>
     */
    protected function createOndatoSession(array $userData): array
    {
        /** @var OndatoService $ondatoService */
        $ondatoService = app(OndatoService::class);
        $user = $userData['user'] ?? null;

        if (! $user instanceof \App\Models\User) {
            // The old fallback returned a fabricated Ondato session here.
            // Removed: a verification session must come from the real
            // Ondato API (or fail), never from invented data.
            throw new InvalidArgumentException(
                'Ondato verification requires a User instance; a simulated session is never issued.'
            );
        }

        return $ondatoService->createIdentityVerification($user, $userData);
    }

    /**
     * Get Ondato verification result.
     *
     * Delegates to OndatoService for real API integration.
     *
     * @return array<string, mixed>
     */
    protected function getOndatoResult(string $sessionId): array
    {
        /** @var OndatoService $ondatoService */
        $ondatoService = app(OndatoService::class);

        return $ondatoService->getIdentityVerificationStatus($sessionId);
    }

    /**
     * Labels every simulated provider payload as sandbox, never verified.
     * (The provider's own `status` string is left intact for backwards
     * compatibility with existing callers; the `sandbox` / `verified`
     * flags are what callers must check — see EnhancedKycService, which
     * routes any sandbox-labelled result to manual review.).
     *
     * @return array{sandbox: bool, simulated: bool, verified: bool, provider: string}
     */
    private function sandboxLabel(): array
    {
        return [
            'sandbox'   => true,
            'simulated' => true,
            'verified'  => false,
            'provider'  => 'sandbox',
        ];
    }
}
