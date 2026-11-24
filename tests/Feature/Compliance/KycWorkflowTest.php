<?php

declare(strict_types=1);

use App\Domain\Compliance\Contracts\SanctionsScreeningInterface;
use App\Domain\Compliance\Kyc\Exceptions\KycRestrictionException;
use App\Domain\Compliance\Kyc\Providers\SandboxBankAccountOwnershipVerifier;
use App\Domain\Compliance\Kyc\Providers\SandboxKycProvider;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Models\KycBankAccountVerification;
use App\Domain\Compliance\Models\KycManualReview;
use App\Domain\Compliance\Models\KycVerification;
use App\Domain\Compliance\Models\SuspiciousActivityReport;
use App\Domain\Compliance\Services\CustomerRiskService;
use App\Domain\Compliance\Services\EnhancedKycService;
use App\Domain\Compliance\Services\KycAmlMonitoringService;
use App\Domain\Compliance\Services\KycManualReviewService;
use App\Domain\Compliance\Services\KycRestrictionService;
use App\Domain\Compliance\Services\KycService;
use App\Domain\Compliance\Services\KycVerificationGate;
use App\Domain\Compliance\Services\KycVerificationWorkflowService;
use App\Domain\Compliance\Services\SarWorkflowService;
use App\Domain\Compliance\Services\SecureDocumentStorage;
use App\Domain\Compliance\Services\SuspiciousActivityReportService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    $this->user = User::factory()->create();
    $this->reviewer = User::factory()->create();
    $this->actingAs($this->user);

    $gate = app(KycVerificationGate::class);
    $this->workflow = new KycVerificationWorkflowService(
        app(KycService::class),
        app(EnhancedKycService::class),
        app(SecureDocumentStorage::class),
        app(KycManualReviewService::class),
        app(CustomerRiskService::class),
        $gate,
        new SandboxKycProvider($gate),
        new SandboxBankAccountOwnershipVerifier($gate),
        null,
    );
    $this->manualReview = app(KycManualReviewService::class);
    $this->restrictions = app(KycRestrictionService::class);
    $this->sar = app(SarWorkflowService::class);
});

describe('KYC workflow steps', function () {
    it('records phone and email verification hooks without storing secrets', function () {
        expect($this->workflow->recordContactVerification($this->user, 'email', true))->toBeTrue()
            ->and($this->workflow->recordContactVerification($this->user, 'phone', true))->toBeTrue()
            ->and($this->workflow->isContactVerified($this->user->refresh(), 'email'))->toBeTrue()
            ->and($this->workflow->isContactVerified($this->user, 'phone'))->toBeTrue();

        expect(AuditLog::forUser($this->user->uuid)->where('action', 'kyc.contact_verified')->count())->toBe(2);
    });

    it('rejects an unknown contact channel', function () {
        $this->workflow->recordContactVerification($this->user, 'fax', true);
    })->throws(InvalidArgumentException::class);

    it('national-ID step stores only last-4 and hash, never the full number', function () {
        $verification = $this->workflow->submitNationalId($this->user, [
            'national_id_number' => '001234567890',
            'country_code'       => 'IR',
        ]);

        expect($verification->type)->toBe(KycVerification::TYPE_IDENTITY)
            ->and($verification->document_type)->toBe('national_id')
            ->and($verification->verification_data['national_id']['last4'])->toBe('7890')
            ->and($verification->verification_data['national_id']['hash'])->toBe(hash('sha256', '001234567890'))
            ->and(json_encode($verification->verification_data))->not->toContain('001234567890');
    });

    it('requires national ID number and country', function () {
        $this->workflow->submitNationalId($this->user, ['national_id_number' => '']);
    })->throws(InvalidArgumentException::class);

    it('document upload stores privately with a hash and audit entry', function () {
        $document = $this->workflow->uploadDocument(
            $this->user,
            UploadedFile::fake()->image('passport.jpg'),
            'passport'
        );

        expect($document->document_type)->toBe('passport')
            ->and($document->file_hash)->toBeString()
            ->and($document->file_hash)->toHaveLength(64);

        Storage::disk('private')->assertExists($document->file_path);
        expect($this->user->refresh()->kyc_status)->toBe('pending');
        expect(AuditLog::forUser($this->user->uuid)->where('action', 'kyc.document_uploaded')->exists())->toBeTrue();
    });

    it('liveness with no real provider goes to manual review, never auto-pass', function () {
        $result = $this->workflow->requestLiveness($this->user);

        expect($result['status'])->toBe('requires_manual_review')
            ->and($result['verified'])->toBeFalse()
            ->and($result['sandbox'])->toBeTrue()
            ->and($result['provider'])->toBe('sandbox');

        $verification = KycVerification::find($result['verification_id']);
        expect($verification->status)->toBe(KycVerification::STATUS_REQUIRES_MANUAL_REVIEW);
        expect($this->manualReview->queue()->where('kyc_verification_id', $verification->getKey())->count())->toBe(1);
    });

    it('bank-account ownership stores only last-4 and hash, and stays in manual review', function () {
        $record = $this->workflow->verifyBankAccountOwnership($this->user, [
            'account_holder_name' => 'Saeed Sajjadi',
            'account_number'      => 'IR82015000000123456789',
        ]);

        expect($record->status)->toBe(KycBankAccountVerification::STATUS_REQUIRES_MANUAL_REVIEW)
            ->and($record->account_last4)->toBe('6789')
            ->and($record->account_hash)->toBe(hash('sha256', 'IR82015000000123456789'))
            ->and($record->isVerified())->toBeFalse()
            ->and(json_encode($record->toArray()))->not->toContain('IR82015000000123456789');
    });

    it('walks the step ladder: contact -> national id -> documents -> liveness -> manual review', function () {
        expect($this->workflow->currentStep($this->user))->toBe('contact_verification');

        $this->workflow->recordContactVerification($this->user, 'email', true);
        $this->workflow->recordContactVerification($this->user, 'phone', true);
        expect($this->workflow->currentStep($this->user->refresh()))->toBe('national_id');

        $this->workflow->submitNationalId($this->user, ['national_id_number' => '001234567890', 'country_code' => 'IR']);
        expect($this->workflow->currentStep($this->user->refresh()))->toBe('document_upload');

        $this->workflow->uploadDocument($this->user, UploadedFile::fake()->image('passport.jpg'), 'passport');
        expect($this->workflow->currentStep($this->user->refresh()))->toBe('liveness');

        $this->workflow->requestLiveness($this->user);
        expect($this->workflow->currentStep($this->user->refresh()))->toBe('manual_review');
    });

    it('verification history returns verifications, reviews and the audit trail', function () {
        $this->workflow->recordContactVerification($this->user, 'email', true);
        $this->workflow->submitNationalId($this->user, ['national_id_number' => '001234567890', 'country_code' => 'IR']);
        $this->workflow->requestLiveness($this->user);

        $history = $this->workflow->getVerificationHistory($this->user->refresh());

        expect($history['verifications'])->toHaveCount(2)
            ->and($history['manual_reviews'])->toHaveCount(1)
            ->and($history['audit_log']->pluck('action'))->toContain('kyc.national_id_submitted')
            ->and($history['audit_log']->pluck('action'))->toContain('kyc.manual_review.requested');
    });

    it('risk assessment creates a customer risk profile', function () {
        $profile = $this->workflow->assessRisk($this->user);

        expect($profile->user_id)->toBe($this->user->id)
            ->and($profile->risk_score)->not->toBeNull()
            ->and($profile->risk_rating)->toBeString();
    });
});

describe('secure document storage', function () {
    it('owner can issue and verify an access token; others cannot', function () {
        $storage = app(SecureDocumentStorage::class);
        $document = $this->workflow->uploadDocument($this->user, UploadedFile::fake()->image('passport.jpg'), 'passport');

        $token = $storage->issueAccessToken($document, $this->user);

        expect($storage->verifyAccessToken($token, $document))->toBeTrue()
            ->and($storage->verifyAccessToken($token . 'tampered', $document))->toBeFalse()
            ->and($storage->authorizedPath($this->user, $document, $token))->toBe($document->file_path)
            ->and($storage->authorizedPath($this->reviewer, $document, $token))->toBeNull()
            ->and($storage->isAuthorized($this->user, $document))->toBeTrue()
            ->and($storage->isAuthorized($this->reviewer, $document))->toBeFalse();
    });

    it('a non-owner cannot issue an access token', function () {
        $storage = app(SecureDocumentStorage::class);
        $document = $this->workflow->uploadDocument($this->user, UploadedFile::fake()->image('passport.jpg'), 'passport');

        $storage->issueAccessToken($document, $this->reviewer);
    })->throws(RuntimeException::class);
});

describe('user restrictions by verification status', function () {
    it('an unverified user is restricted from every money action', function () {
        $this->user->update(['kyc_status' => 'not_submitted']);

        expect($this->restrictions->allowedActions($this->user->refresh()))->toBe([])
            ->and($this->restrictions->isAllowed($this->user, 'transfer'))->toBeFalse()
            ->and($this->restrictions->isAllowed($this->user, 'withdraw'))->toBeFalse()
            ->and($this->restrictions->isAllowed($this->user, 'deposit'))->toBeFalse();
    });

    it('a pending user may deposit but cannot withdraw or transfer', function () {
        $this->user->update(['kyc_status' => 'pending']);

        expect($this->restrictions->isAllowed($this->user->refresh(), 'deposit'))->toBeTrue()
            ->and($this->restrictions->isAllowed($this->user, 'withdraw'))->toBeFalse()
            ->and($this->restrictions->isAllowed($this->user, 'transfer'))->toBeFalse()
            ->and($this->restrictions->isAllowed($this->user, 'card_issue'))->toBeFalse();
    });

    it('an approved user may use money actions', function () {
        $this->user->update(['kyc_status' => 'approved', 'kyc_level' => 'basic']);

        expect($this->restrictions->isAllowed($this->user->refresh(), 'transfer'))->toBeTrue()
            ->and($this->restrictions->isAllowed($this->user, 'withdraw'))->toBeTrue()
            ->and($this->restrictions->isAllowed($this->user, 'trade'))->toBeTrue();
    });

    it('assertAllowed throws for a restricted action', function () {
        $this->user->update(['kyc_status' => 'rejected']);

        $this->restrictions->assertAllowed($this->user->refresh(), 'transfer');
    })->throws(KycRestrictionException::class);
});

describe('manual review queue', function () {
    it('approve transitions the user to approved and records history', function () {
        $enhanced = app(EnhancedKycService::class);
        $verification = $enhanced->startVerification($this->user, KycVerification::TYPE_IDENTITY, ['provider' => 'sandbox']);
        $this->manualReview->submitForReview($verification, 'sandbox evidence needs a human');

        $review = $this->manualReview->approve($verification->refresh(), $this->reviewer, 'Documents look genuine');

        expect($review->decision)->toBe(KycManualReview::DECISION_APPROVED)
            ->and($verification->refresh()->status)->toBe(KycVerification::STATUS_COMPLETED)
            ->and($this->user->refresh()->kyc_status)->toBe('approved')
            ->and($this->user->kyc_level)->toBe('basic');

        $history = $this->manualReview->historyForUser($this->user);
        expect($history)->toHaveCount(2); // pending queue row + approval row
        expect(AuditLog::forUser($this->user->uuid)->where('action', 'kyc.manual_review.approved')->exists())->toBeTrue();
    });

    it('reject transitions the user to rejected and records history', function () {
        $enhanced = app(EnhancedKycService::class);
        $verification = $enhanced->startVerification($this->user, KycVerification::TYPE_IDENTITY, ['provider' => 'sandbox']);
        $this->manualReview->submitForReview($verification, 'sandbox evidence needs a human');

        $review = $this->manualReview->reject($verification->refresh(), $this->reviewer, 'Document does not match the applicant');

        expect($review->decision)->toBe(KycManualReview::DECISION_REJECTED)
            ->and($verification->refresh()->status)->toBe(KycVerification::STATUS_FAILED)
            ->and($this->user->refresh()->kyc_status)->toBe('rejected');
        expect(AuditLog::forUser($this->user->uuid)->where('action', 'kyc.manual_review.rejected')->exists())->toBeTrue();
    });

    it('an already completed verification cannot be reviewed again', function () {
        $enhanced = app(EnhancedKycService::class);
        $verification = $enhanced->startVerification($this->user, KycVerification::TYPE_IDENTITY, ['provider' => 'sandbox']);
        $this->manualReview->submitForReview($verification, 'check');
        $this->manualReview->approve($verification->refresh(), $this->reviewer);

        $this->manualReview->approve($verification->refresh(), $this->reviewer);
    })->throws(LogicException::class);
});

describe('SAR workflow', function () {
    it('creates a draft, submits for review, and escalates to critical', function () {
        $sar = $this->sar->create([
            'activity_description' => 'Rapid movement of funds inconsistent with profile.',
            'activity_types'       => ['layering'],
            'priority'             => SuspiciousActivityReport::PRIORITY_HIGH,
            'total_amount'         => 50000,
            'transaction_count'    => 3,
        ], $this->user);

        expect($sar->status)->toBe(SuspiciousActivityReport::STATUS_DRAFT)
            ->and($sar->sar_number)->toStartWith('SAR-');

        $sar = $this->sar->submitForReview($sar, $this->reviewer);
        expect($sar->status)->toBe(SuspiciousActivityReport::STATUS_PENDING_REVIEW);

        $sar = $this->sar->escalate($sar, $this->reviewer, 'Amount exceeds escalation threshold');
        expect($sar->status)->toBe(SuspiciousActivityReport::STATUS_PENDING_REVIEW)
            ->and($sar->priority)->toBe(SuspiciousActivityReport::PRIORITY_CRITICAL);

        expect(AuditLog::forUser($this->user->uuid)->where('action', 'sar.escalated')->exists())->toBeTrue();
    });

    it('records a decision and files only with a real regulator reference after QA', function () {
        $sar = $this->sar->create([
            'activity_description' => 'Structuring pattern detected.',
            'transaction_count'    => 2,
        ], $this->user);
        $this->sar->submitForReview($sar, $this->reviewer);
        $sar->update(['investigation_findings' => 'Confirmed structuring across 2 transactions.']);

        $sar = $this->sar->recordDecision($sar->refresh(), SuspiciousActivityReport::DECISION_FILE_SAR, 'Evidence supports filing.', $this->reviewer);
        expect($sar->decision)->toBe(SuspiciousActivityReport::DECISION_FILE_SAR);

        $sar = $this->sar->recordQaReview($sar, $this->reviewer, 'Package complete.', true);
        expect($sar->qa_approved)->toBeTrue();

        $sar = $this->sar->fileExternally($sar, 'FINCEN-2026-12345', 'US-FinCEN', $this->reviewer);
        expect($sar->status)->toBe(SuspiciousActivityReport::STATUS_SUBMITTED)
            ->and($sar->filing_reference)->toBe('FINCEN-2026-12345')
            ->and($sar->filed_with_regulator)->toBeTrue();
    });

    it('filing without a real reference is rejected', function () {
        $sar = $this->sar->create(['activity_description' => 'Test.', 'transaction_count' => 1], $this->user);
        $this->sar->submitForReview($sar, $this->reviewer);
        $sar->update(['investigation_findings' => 'Findings.']);
        $this->sar->recordDecision($sar->refresh(), SuspiciousActivityReport::DECISION_FILE_SAR, 'File it.', $this->reviewer);
        $this->sar->recordQaReview($sar->refresh(), $this->reviewer, 'OK.', true);

        $this->sar->fileExternally($sar->refresh(), '', 'US-FinCEN', $this->reviewer);
    })->throws(InvalidArgumentException::class);

    it('filing without QA approval is rejected', function () {
        $sar = $this->sar->create(['activity_description' => 'Test.', 'transaction_count' => 1], $this->user);
        $this->sar->submitForReview($sar, $this->reviewer);
        $sar->update(['investigation_findings' => 'Findings.']);
        $this->sar->recordDecision($sar->refresh(), SuspiciousActivityReport::DECISION_FILE_SAR, 'File it.', $this->reviewer);

        $this->sar->fileExternally($sar->refresh(), 'FINCEN-2026-99999', 'US-FinCEN', $this->reviewer);
    })->throws(LogicException::class);

    it('the legacy simulated regulator submission is disabled', function () {
        $sar = $this->sar->create(['activity_description' => 'Test.', 'transaction_count' => 1], $this->user);
        $sar->update([
            'investigation_findings' => 'Findings.',
            'decision'               => SuspiciousActivityReport::DECISION_FILE_SAR,
            'decision_rationale'     => 'Rationale.',
        ]);

        app(SuspiciousActivityReportService::class)->submitToRegulator($sar->refresh());
    })->throws(Exception::class);
});

describe('AML monitoring integration', function () {
    it('invokes the sanctions screening hook and flags a match for manual review', function () {
        $adapter = Mockery::mock(SanctionsScreeningInterface::class);
        $adapter->shouldReceive('screenIndividual')->once()->andReturn([
            'matches'       => ['OFAC' => [['name' => 'Blocked Person']]],
            'lists_checked' => ['OFAC', 'Internal'],
            'total_matches' => 1,
        ]);
        $adapter->shouldReceive('getName')->andReturn('MockScreening');

        $service = new KycAmlMonitoringService($adapter);
        $result = $service->screenUser($this->user);

        expect($result['total_matches'])->toBe(1)
            ->and($result['requires_manual_review'])->toBeTrue()
            ->and($result['lists_checked'])->toContain('OFAC');
        expect($this->user->refresh()->kyc_data['sanctions_match'])->toBeTrue();
        expect($this->restrictions->isAllowed($this->user, 'transfer'))->toBeFalse();
    });

    it('a clear screening does not flag the user', function () {
        $adapter = Mockery::mock(SanctionsScreeningInterface::class);
        $adapter->shouldReceive('screenIndividual')->once()->andReturn([
            'matches'       => [],
            'lists_checked' => ['OFAC', 'Internal'],
            'total_matches' => 0,
        ]);
        $adapter->shouldReceive('getName')->andReturn('MockScreening');

        $service = new KycAmlMonitoringService($adapter);
        $result = $service->screenUser($this->user);

        expect($result['requires_manual_review'])->toBeFalse();
        expect($this->user->refresh()->kyc_data['sanctions_match'])->toBeFalse();
    });

    it('a sanctions-flagged user is restricted even when KYC is approved', function () {
        $this->user->update([
            'kyc_status' => 'approved',
            'kyc_data'   => ['sanctions_match' => true],
        ]);

        expect($this->restrictions->allowedActions($this->user->refresh()))->toBe([]);
    });
});
