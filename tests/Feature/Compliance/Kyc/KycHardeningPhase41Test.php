<?php

declare(strict_types=1);

use App\Domain\Compliance\Kyc\Exceptions\InvalidKycTransitionException;
use App\Domain\Compliance\Kyc\Providers\SandboxIranianKycProvider;
use App\Domain\Compliance\Kyc\Registries\KycProviderRouter;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Models\KycVerification;
use App\Domain\Compliance\Services\BiometricVerificationService;
use App\Domain\Compliance\Services\DocumentAnalysisService;
use App\Domain\Compliance\Services\EnhancedKycService;
use App\Domain\Compliance\Services\IdentityVerificationService;
use App\Domain\Compliance\Services\KycService;
use App\Domain\Compliance\Services\KycStatusMachine;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Phase 4.1 KYC hardening — regression tests.
|
| An arbitrary document upload, a simulated provider result, or a request
| from the user themselves must NEVER produce an approved KYC status.
| Approval only ever comes from a real provider verdict (signature-verified
| webhook, re-confirmed against the provider API) or a human reviewer, and
| every status transition is recorded in the audit log.
*/

beforeEach(function () {
    Storage::fake('private');
    $this->user = User::factory()->create(['kyc_status' => 'not_started']);
});

describe('arbitrary document upload cannot approve KYC', function () {
    it('uploading any document via the public API leaves the user unapproved', function () {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $response = $this->post('/api/kyc/documents', [
            'document' => UploadedFile::fake()->image('passport.jpg'),
            'type'     => 'passport',
        ]);

        $response->assertStatus(200);
        expect($this->user->refresh()->kyc_status)->not->toBe('approved');
    });

    it('running the document step on an arbitrary upload routes to manual review, never approval', function () {
        $enhanced = app(EnhancedKycService::class);
        $verification = $enhanced->startVerification($this->user, KycVerification::TYPE_IDENTITY, ['provider' => 'sandbox']);

        $result = $enhanced->verifyIdentityDocument($verification, 'passport.jpg', 'passport');

        expect($result['success'])->toBeFalse()
            ->and($result['status'])->toBe(KycVerification::STATUS_REQUIRES_MANUAL_REVIEW)
            ->and($verification->refresh()->status)->toBe(KycVerification::STATUS_REQUIRES_MANUAL_REVIEW)
            ->and($this->user->refresh()->kyc_status)->not->toBe('approved');

        $enhanced->completeVerification($verification->refresh());
    })->throws(LogicException::class);

    it('a simulated provider result never passes, even in the sandbox', function () {
        $identity = app(IdentityVerificationService::class);

        $result = $identity->verifyIdentity([
            'first_name'    => 'John',
            'last_name'     => 'Doe',
            'date_of_birth' => '1990-01-01',
        ]);

        expect($result['match_found'])->toBeFalse()
            ->and($result['match_confidence'])->toBe(0)
            ->and($result['verified'])->toBeFalse();

        foreach (['jumio', 'onfido', 'smileid'] as $provider) {
            $providerResult = $identity->getVerificationResult($provider, "{$provider}_abc123");

            expect($providerResult['verified'])->toBeFalse()
                ->and($providerResult['status'])->toBe('requires_manual_review')
                ->and($providerResult)->not->toHaveKey('extracted_data');
        }
    });

    it('fake document extraction establishes no identity', function () {
        $documents = app(DocumentAnalysisService::class);

        $extracted = $documents->extractDocumentData('passport.jpg', 'passport');

        expect($extracted)->not->toHaveKey('first_name')
            ->and($extracted)->not->toHaveKey('last_name')
            ->and($extracted)->not->toHaveKey('document_number')
            ->and($extracted['verified'])->toBeFalse()
            ->and($extracted['status'])->toBe('requires_manual_review');

        $authenticity = $documents->verifyAuthenticity('passport.jpg', 'passport');
        expect($authenticity['is_authentic'])->toBeFalse()
            ->and($authenticity['confidence'])->toBe(0.0);

        $comparison = $documents->compareDocumentWithSelfie('doc.jpg', 'selfie.jpg');
        expect($comparison['match'])->toBeFalse()
            ->and($comparison['confidence'])->toBe(0.0);
    });

    it('fake face match never passes, even in the sandbox', function () {
        $biometric = app(BiometricVerificationService::class);

        $result = $biometric->matchFaces('a.jpg', 'b.jpg');

        expect($result['is_match'])->toBeFalse()
            ->and($result['similarity'])->toBeNull()
            ->and($result['verified'])->toBeFalse();

        $template = $biometric->compareWithTemplate('selfie.jpg', 'face_123');
        expect($template['is_match'])->toBeFalse()
            ->and($template['similarity'])->toBeNull();
    });
});

describe('KYC state machine', function () {
    it('refuses to approve a user who never submitted anything', function () {
        app(KycService::class)->verifyKyc($this->user, 'admin-1', ['level' => 'enhanced']);
    })->throws(InvalidKycTransitionException::class);

    it('refuses to approve without a provider verdict or a human review basis', function () {
        $kyc = app(KycService::class);
        $kyc->submitKyc($this->user, [
            ['type' => 'passport', 'file' => UploadedFile::fake()->image('passport.jpg')],
        ]);

        app(KycStatusMachine::class)->approve($this->user->refresh(), 'admin-1', 'simulated');
    })->throws(InvalidArgumentException::class);

    it('records an audit row on every transition', function () {
        $kyc = app(KycService::class);
        $kyc->submitKyc($this->user, [
            ['type' => 'passport', 'file' => UploadedFile::fake()->image('passport.jpg')],
        ]);

        $transitions = AuditLog::where('action', 'kyc.status_transition')->orderBy('id')->get();
        expect($transitions)->toHaveCount(1)
            ->and($transitions[0]->old_values['kyc_status'])->toBe('not_started')
            ->and($transitions[0]->new_values['kyc_status'])->toBe('pending');

        $kyc->verifyKyc($this->user->refresh(), 'admin-1', ['level' => 'enhanced', 'basis' => 'manual_review']);

        $transitions = AuditLog::where('action', 'kyc.status_transition')->orderBy('id')->get();
        expect($transitions)->toHaveCount(2)
            ->and($transitions[1]->old_values['kyc_status'])->toBe('pending')
            ->and($transitions[1]->new_values['kyc_status'])->toBe('approved');

        $kyc->rejectKyc($this->user->refresh(), 'admin-1', 'Documents do not match');
        expect($this->user->refresh()->kyc_status)->toBe('rejected');

        $transitions = AuditLog::where('action', 'kyc.status_transition')->orderBy('id')->get();
        expect($transitions)->toHaveCount(3)
            ->and($transitions[2]->old_values['kyc_status'])->toBe('approved')
            ->and($transitions[2]->new_values['kyc_status'])->toBe('rejected');
    });

    it('refuses resubmission while approved', function () {
        $kyc = app(KycService::class);
        $kyc->submitKyc($this->user, [
            ['type' => 'passport', 'file' => UploadedFile::fake()->image('passport.jpg')],
        ]);
        $kyc->verifyKyc($this->user->refresh(), 'admin-1', ['level' => 'enhanced', 'basis' => 'manual_review']);

        $kyc->submitKyc($this->user->refresh(), [
            ['type' => 'passport', 'file' => UploadedFile::fake()->image('passport.jpg')],
        ]);
    })->throws(InvalidKycTransitionException::class);

    it('a human approval after a rejection works when a new verification was reviewed', function () {
        $kyc = app(KycService::class);
        $kyc->submitKyc($this->user, [
            ['type' => 'passport', 'file' => UploadedFile::fake()->image('passport.jpg')],
        ]);
        $kyc->rejectKyc($this->user->refresh(), 'admin-1', 'Unclear document');

        $reviewer = User::factory()->create();
        $enhanced = app(EnhancedKycService::class);
        $verification = $enhanced->startVerification($this->user->refresh(), KycVerification::TYPE_IDENTITY, ['provider' => 'sandbox']);
        $manualReview = app(App\Domain\Compliance\Services\KycManualReviewService::class);
        $manualReview->submitForReview($verification, 'resubmission needs a human');

        $review = $manualReview->approve($verification->refresh(), $reviewer, 'Resubmitted documents look genuine');

        expect($review->decision)->toBe(App\Domain\Compliance\Models\KycManualReview::DECISION_APPROVED)
            ->and($this->user->refresh()->kyc_status)->toBe('approved');
    });

    it('a provider verdict approves a previously rejected user', function () {
        $kyc = app(KycService::class);
        $kyc->submitKyc($this->user, [
            ['type' => 'passport', 'file' => UploadedFile::fake()->image('passport.jpg')],
        ]);
        $kyc->rejectKyc($this->user->refresh(), 'admin-1', 'Unclear document');

        $kyc->verifyKyc($this->user->refresh(), 'ondato-webhook', ['level' => 'enhanced', 'basis' => 'provider_verdict']);

        expect($this->user->refresh()->kyc_status)->toBe('approved');
    });

    it('expires an approved user only from approved and audits it', function () {
        $this->user->update(['kyc_status' => 'approved', 'kyc_expires_at' => now()->subDay()]);

        expect(app(KycService::class)->checkExpiredKyc($this->user))->toBeTrue()
            ->and($this->user->refresh()->kyc_status)->toBe('expired');

        $transition = AuditLog::where('action', 'kyc.status_transition')->orderBy('id')->first();
        expect($transition)->not->toBeNull()
            ->and($transition->old_values['kyc_status'])->toBe('approved')
            ->and($transition->new_values['kyc_status'])->toBe('expired');
    });
});

describe('users cannot modify their own KYC status via public APIs', function () {
    it('a kyc_status field smuggled into the profile update is ignored', function () {
        $this->actingAs($this->user)->put('/user/profile-information', [
            'name'       => $this->user->name,
            'email'      => $this->user->email,
            'kyc_status' => 'approved',
        ]);

        expect($this->user->refresh()->kyc_status)->toBe('not_started');
    });

    it('a kyc_status field smuggled into a document upload is ignored', function () {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $this->post('/api/kyc/documents', [
            'document'   => UploadedFile::fake()->image('passport.jpg'),
            'type'       => 'passport',
            'kyc_status' => 'approved',
        ])->assertStatus(200);

        expect($this->user->refresh()->kyc_status)->toBe('not_started');
    });
});

describe('missing provider fails closed', function () {
    it('the router throws instead of returning a provider when nothing is routed', function () {
        $router = new KycProviderRouter(factories: [], routing: []);

        $router->resolve(App\Domain\Compliance\Kyc\Enums\KycPurpose::TRUSTCERT);
    })->throws(RuntimeException::class);

    it('the router throws for an unknown provider name', function () {
        $router = new KycProviderRouter(factories: [], routing: ['trustcert' => 'no-such-provider']);

        $router->resolve(App\Domain\Compliance\Kyc\Enums\KycPurpose::TRUSTCERT);
    })->throws(RuntimeException::class);
});

describe('Iranian KYC sandbox provider', function () {
    it('validates the national-code checksum format without claiming an identity lookup', function () {
        $provider = app(SandboxIranianKycProvider::class);

        // 0499370899 passes the Iranian national-code checksum; 0499370898 does not.
        $valid = $provider->validateNationalId('0499370899');
        expect($valid['format_valid'])->toBeTrue()
            ->and($valid['verified'])->toBeFalse()
            ->and($valid['status'])->toBe('requires_manual_review');

        expect($provider->validateNationalId('0499370898')['format_valid'])->toBeFalse();
        expect($provider->validateNationalId('1111111111')['format_valid'])->toBeFalse();
        expect($provider->validateNationalId('123')['format_valid'])->toBeFalse();
    });

    it('never approves national identity, mobile ownership, bank ownership or documents', function () {
        $provider = app(SandboxIranianKycProvider::class);

        foreach ([
            $provider->verifyNationalIdentity($this->user->id, ['national_code' => '0499370899']),
            $provider->verifyMobileOwnership($this->user->id, ['mobile' => '09123456789']),
            $provider->verifyBankAccountOwnership($this->user->id, ['account_last4' => '1234']),
            $provider->verifyDocument($this->user->id, ['type' => 'national_id']),
        ] as $result) {
            expect($result['verified'])->toBeFalse()
                ->and($result['status'])->toBe('requires_manual_review')
                ->and($result['sandbox'])->toBeTrue();
        }
    });

    it('routes human review through the manual-review queue, never auto-approval', function () {
        $provider = app(SandboxIranianKycProvider::class);

        $result = $provider->requestHumanReview($this->user->id, 'Iranian KYC needs a human decision');

        expect($result['status'])->toBe('requires_manual_review')
            ->and($result['verified'])->toBeFalse()
            ->and($result['queued_for_human_review'])->toBeTrue();
    });
});

describe('demo KYC bypass flags in production', function () {
    it('demo skip_kyc and auto_approve default to off under APP_ENV=production', function () {
        $saved = [];
        foreach (['APP_ENV', 'DEMO_SKIP_KYC', 'DEMO_AUTO_APPROVE'] as $key) {
            $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        }

        try {
            $_SERVER['APP_ENV'] = 'production';
            $_ENV['APP_ENV'] = 'production';
            putenv('APP_ENV=production');

            $config = require base_path('config/demo.php');

            expect($config['features']['skip_kyc'])->toBeFalse()
                ->and($config['features']['auto_approve'])->toBeFalse();
        } finally {
            foreach ($saved as $key => [$server, $env, $getenv]) {
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                } else {
                    unset($_SERVER[$key]);
                }
                if ($env !== null) {
                    $_ENV[$key] = $env;
                } else {
                    unset($_ENV[$key]);
                }
                if ($getenv !== false) {
                    putenv("{$key}={$getenv}");
                } else {
                    putenv($key);
                }
            }
        }
    });

    it('the deploy preflight FAILs (blocking) when demo flags are on', function () {
        config(['demo.features' => [
            'instant_deposits'     => true,
            'skip_kyc'             => true,
            'mock_external_apis'   => true,
            'fixed_exchange_rates' => true,
            'auto_approve'         => true,
        ]]);

        $exitCode = Artisan::call('ops:verify-env', ['--json' => true, '--strict' => true]);

        /** @var array{checks: list<array{name: string, result: string}>} $decoded */
        $decoded = json_decode(Artisan::output(), true);
        $checks = collect($decoded['checks'])->keyBy('name');

        expect($exitCode)->toBe(1)
            ->and($checks['demo.features']['result'])->toBe('FAIL');
    });
});
