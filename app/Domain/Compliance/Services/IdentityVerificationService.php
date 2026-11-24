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
     *
     * There is no identity database behind this service — the Jumio /
     * Onfido / SmileID branches are simulated. The old implementation
     * fabricated a match for "John Doe" (confidence 95) and rolled a
     * random credit-bureau hit, i.e. it could "verify" an arbitrary
     * person. Both are removed: a simulated check finds no record, ever.
     * The result stays labelled sandbox / requires_manual_review so the
     * only path forward is human review.
     */
    public function verifyIdentity(array $data): array
    {
        if (! $this->gate->simulatedProvidersAllowed()) {
            // Fail closed: no simulated identity match in a production path.
            return $this->gate->failClosedIdentity();
        }

        // No identity database is consulted here — see checkIdentityDatabase().
        $identityCheck = $this->checkIdentityDatabase($data);
        $creditCheck = $this->checkCreditBureaus($data);

        return [
            'match_found'      => false,
            'match_confidence' => 0,
            'sources_checked'  => ['National Identity Database', 'Credit Bureaus'],
            'discrepancies'    => [],
            'identity_found'   => $identityCheck['found'],
            'credit_found'     => $creditCheck['found'],
            'sandbox'          => true,
            'simulated'        => true,
            'verified'         => false,
            'status'           => 'requires_manual_review',
            'provider'         => 'sandbox',
        ];
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
     *
     * No identity database exists behind this service. The old version
     * returned a fabricated "John Doe" record (confidence 95) for that
     * name — a hardcoded identity that could be presented as a match.
     * Removed: a simulated lookup never finds anyone.
     */
    protected function checkIdentityDatabase(array $data): array
    {
        return ['found' => false];
    }

    /**
     * Check credit bureaus (simulated).
     *
     * The old version rolled a random 70% "found" with a random
     * confidence — non-deterministic fabricated evidence. Removed: a
     * simulated check never finds a record.
     */
    protected function checkCreditBureaus(array $data): array
    {
        return [
            'found'      => false,
            'confidence' => 0,
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
     *
     * Simulated — the old version returned a fabricated "passed" result
     * (confidence 92.5) with an invented identity (John Doe, passport
     * P123456789). A simulated result must never look like a pass, so it
     * now returns requires_manual_review with no extracted data: any
     * caller that only checks `result` / `status` can no longer mistake
     * it for a verification.
     */
    protected function getJumioResult(string $sessionId): array
    {
        return array_merge([
            'status'     => 'requires_manual_review',
            'result'     => 'requires_manual_review',
            'confidence' => 0.0,
            'checks'     => [
                'document_validity' => false,
                'face_match'        => false,
                'data_extraction'   => false,
                'fraud_check'       => false,
            ],
        ], $this->sandboxLabel());
    }

    /**
     * Get Onfido verification result.
     *
     * Simulated — the old version returned fabricated "clear" reports.
     * Now requires_manual_review with no reports: a simulated result is
     * never evidence.
     */
    protected function getOnfidoResult(string $sessionId): array
    {
        return array_merge([
            'status'     => 'requires_manual_review',
            'result'     => 'requires_manual_review',
            'sub_result' => null,
            'reports'    => [],
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
     * Simulated — the old version returned a fabricated "completed"
     * result (confidence 99.5) with an invented identity (Adebayo
     * Ogunlesi). Now requires_manual_review with no extracted data.
     *
     * @return array<string, mixed>
     */
    protected function getSmileIdResult(string $sessionId): array
    {
        return array_merge([
            'status'     => 'requires_manual_review',
            'confidence' => 0.0,
            'checks'     => [
                'document_validity'  => false,
                'face_match'         => false,
                'liveness_check'     => false,
                'id_authority_check' => false,
            ],
            'smile_job_id' => null,
            'result_code'  => null,
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
