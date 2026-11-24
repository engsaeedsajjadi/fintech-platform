<?php

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Exceptions\SimulatedVerificationNotAvailableException;
use App\Domain\Compliance\Kyc\Providers\SandboxKycProvider;
use App\Domain\Compliance\Models\KycVerification;
use App\Domain\Compliance\Services\BiometricVerificationService;
use App\Domain\Compliance\Services\DocumentAnalysisService;
use App\Domain\Compliance\Services\EnhancedKycService;
use App\Domain\Compliance\Services\IdentityVerificationService;
use App\Domain\Compliance\Services\KycVerificationGate;
use App\Models\User;

/*
| Simulated KYC providers must FAIL CLOSED outside a sandbox environment
| and must NEVER return a passing verdict from simulated code.
|
| Production is simulated here via config (kyc.verification.mode =
| 'production'), which KycVerificationGate treats exactly like
| APP_ENV=production.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->gate = app(KycVerificationGate::class);
    $this->biometric = app(BiometricVerificationService::class);
    $this->documents = app(DocumentAnalysisService::class);
    $this->identity = app(IdentityVerificationService::class);
});

describe('KycVerificationGate', function () {
    it('reports production mode and blocks simulated providers there', function () {
        config(['kyc.verification.mode' => 'production']);

        expect($this->gate->isProduction())->toBeTrue()
            ->and($this->gate->simulatedProvidersAllowed())->toBeFalse()
            ->and($this->gate->isSandboxAllowed())->toBeFalse();
    });

    it('allows simulated providers in the testing sandbox by default', function () {
        expect($this->gate->isProduction())->toBeFalse()
            ->and($this->gate->simulatedProvidersAllowed())->toBeTrue();
    });

    it('blocks simulated providers when the flag is disabled, even in sandbox', function () {
        config(['kyc.verification.simulated_providers_enabled' => false]);

        expect($this->gate->simulatedProvidersAllowed())->toBeFalse();
    });

    it('reports no real provider configured without credentials', function () {
        expect($this->gate->isRealProviderConfigured('ondato'))->toBeFalse()
            ->and($this->gate->isRealProviderConfigured('bridge'))->toBeFalse()
            ->and($this->gate->isRealProviderConfigured('sandbox'))->toBeFalse();
    });
});

describe('simulated providers fail closed in production mode', function () {
    beforeEach(function () {
        config(['kyc.verification.mode' => 'production']);
    });

    it('liveness never passes in production', function () {
        $result = $this->biometric->checkLiveness('selfie.jpg');

        expect($result['is_live'])->toBeFalse()
            ->and($result['confidence'])->toBe(0.0)
            ->and($result['verified'])->toBeFalse()
            ->and($result['status'])->toBe('requires_manual_review');
    });

    it('face match never passes in production', function () {
        $result = $this->biometric->matchFaces('a.jpg', 'b.jpg');

        expect($result['is_match'])->toBeFalse()
            ->and($result['similarity'])->toBeNull()
            ->and($result['verified'])->toBeFalse();
    });

    it('no face template is issued in production', function () {
        $result = $this->biometric->createFaceTemplate('selfie.jpg');

        expect($result['template_id'])->toBeNull();
    });

    it('document authenticity never passes in production', function () {
        $result = $this->documents->verifyAuthenticity('passport.jpg', 'passport');

        expect($result['is_authentic'])->toBeFalse()
            ->and($result['confidence'])->toBe(0.0)
            ->and($result['verified'])->toBeFalse();
    });

    it('document extraction returns no fabricated identity in production', function () {
        $result = $this->documents->extractDocumentData('passport.jpg', 'passport');

        expect($result)->not->toHaveKey('first_name')
            ->and($result)->not->toHaveKey('document_number')
            ->and($result['status'])->toBe('requires_manual_review');
    });

    it('document/selfie comparison never matches in production', function () {
        $result = $this->documents->compareDocumentWithSelfie('doc.jpg', 'selfie.jpg');

        expect($result['match'])->toBeFalse()
            ->and($result['confidence'])->toBe(0.0);
    });

    it('identity verification never matches in production', function () {
        $result = $this->identity->verifyIdentity([
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'date_of_birth' => '1990-01-01',
        ]);

        expect($result['match_found'])->toBeFalse()
            ->and($result['match_confidence'])->toBe(0)
            ->and($result['status'])->toBe('not_verified');
    });

    it('simulated provider sessions throw in production', function () {
        $this->identity->createVerificationSession('smileid', ['first_name' => 'A']);
    })->throws(SimulatedVerificationNotAvailableException::class);

    it('simulated provider results throw in production', function () {
        $this->identity->getVerificationResult('jumio', 'jumio_123');
    })->throws(SimulatedVerificationNotAvailableException::class);

    it('sandbox provider throws in production', function () {
        $provider = app(SandboxKycProvider::class);

        $provider->verifyLiveness($this->user->id, []);
    })->throws(SimulatedVerificationNotAvailableException::class);
});

describe('simulated providers are labelled sandbox and never verified', function () {
    it('liveness is labelled sandbox, not verified, requires manual review', function () {
        $result = $this->biometric->checkLiveness('selfie.jpg');

        expect($result['sandbox'])->toBeTrue()
            ->and($result['simulated'])->toBeTrue()
            ->and($result['verified'])->toBeFalse()
            ->and($result['status'])->toBe('requires_manual_review');
    });

    it('face match never passes, even in the sandbox', function () {
        $result = $this->biometric->matchFaces('a.jpg', 'b.jpg');

        expect($result['similarity'])->toBeNull()
            ->and($result['is_match'])->toBeFalse()
            ->and($result['sandbox'])->toBeTrue()
            ->and($result['verified'])->toBeFalse();
    });

    it('document extraction is labelled sandbox', function () {
        $result = $this->documents->extractDocumentData('passport.jpg', 'passport');

        expect($result['sandbox'])->toBeTrue()
            ->and($result['verified'])->toBeFalse()
            ->and($result['status'])->toBe('requires_manual_review');
    });

    it('identity verification is labelled sandbox', function () {
        $result = $this->identity->verifyIdentity([
            'first_name' => 'John',
            'last_name'  => 'Doe',
        ]);

        expect($result['sandbox'])->toBeTrue()
            ->and($result['verified'])->toBeFalse()
            ->and($result['status'])->toBe('requires_manual_review');
    });

    it('sandbox provider never approves liveness or documents', function () {
        $provider = app(SandboxKycProvider::class);

        $liveness = $provider->verifyLiveness($this->user->id, []);
        $document = $provider->verifyDocument($this->user->id, ['type' => 'passport']);

        expect($liveness['status'])->toBe('requires_manual_review')
            ->and($liveness['verified'])->toBeFalse()
            ->and($liveness['sandbox'])->toBeTrue()
            ->and($document['status'])->toBe('requires_manual_review')
            ->and($document['verified'])->toBeFalse();
    });

    it('sandbox provider status is always pending and webhooks are rejected', function () {
        $provider = app(SandboxKycProvider::class);

        expect($provider->getStatus($this->user->id)['status'])->toBe('pending')
            ->and($provider->getHostedLink($this->user->id, App\Domain\Compliance\Kyc\Enums\KycPurpose::TRUSTCERT))
            ->toContain('sandbox')
            ->and(($provider->getWebhookValidator())('{}', 'sig'))->toBeFalse()
            ->and($provider->normalizeWebhookPayload(['status' => 'approved']))->toBeNull();
    });
});

describe('EnhancedKycService fails closed on sandbox evidence', function () {
    it('document verification routes to manual review and does not approve the user', function () {
        $enhanced = app(EnhancedKycService::class);
        $verification = $enhanced->startVerification($this->user, KycVerification::TYPE_IDENTITY, ['provider' => 'sandbox']);

        $result = $enhanced->verifyIdentityDocument($verification, 'passport.jpg', 'passport');

        expect($result['success'])->toBeFalse()
            ->and($result['status'])->toBe(KycVerification::STATUS_REQUIRES_MANUAL_REVIEW)
            ->and($result['requires_manual_review'])->toBeTrue();

        $verification->refresh();
        expect($verification->status)->toBe(KycVerification::STATUS_REQUIRES_MANUAL_REVIEW);

        $this->user->refresh();
        expect($this->user->kyc_status)->not->toBe('approved');
    });

    it('biometric verification routes to manual review and does not approve the user', function () {
        $enhanced = app(EnhancedKycService::class);
        $verification = $enhanced->startVerification($this->user, KycVerification::TYPE_IDENTITY, ['provider' => 'sandbox']);

        $result = $enhanced->verifyBiometrics($verification, 'selfie.jpg', 'doc.jpg');

        expect($result['success'])->toBeFalse()
            ->and($result['status'])->toBe(KycVerification::STATUS_REQUIRES_MANUAL_REVIEW);

        $verification->refresh();
        expect($verification->status)->toBe(KycVerification::STATUS_REQUIRES_MANUAL_REVIEW);
    });

    it('completeVerification refuses a sandbox-sourced verification', function () {
        $enhanced = app(EnhancedKycService::class);
        $verification = $enhanced->startVerification($this->user, KycVerification::TYPE_IDENTITY, ['provider' => 'sandbox']);
        $enhanced->verifyIdentityDocument($verification, 'passport.jpg', 'passport');

        $enhanced->completeVerification($verification->refresh());
    })->throws(LogicException::class);
});
