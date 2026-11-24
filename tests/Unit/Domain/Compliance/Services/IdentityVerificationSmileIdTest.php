<?php

declare(strict_types=1);

use App\Domain\Compliance\Services\IdentityVerificationService;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->service = new IdentityVerificationService();
});

describe('IdentityVerificationService – Smile ID', function () {
    it('creates a session with default country code', function () {
        $session = $this->service->createVerificationSession('smileid', [
            'first_name' => 'Adebayo',
            'last_name'  => 'Ogunlesi',
        ]);

        expect($session['session_id'])->toStartWith('smileid_');
        expect($session['upload_url'])->toStartWith('https://upload.smileidentity.com/');
        expect($session['country_code'])->toBe('NG');
        expect($session['id_type'])->toBe('NATIONAL_ID');
        expect($session['job_type'])->toBe(5);
        expect($session)->toHaveKey('expires_at');
    });

    it('creates a session with explicit country code and id type', function () {
        $session = $this->service->createVerificationSession('smileid', [
            'first_name'   => 'Kwame',
            'last_name'    => 'Mensah',
            'country_code' => 'GH',
            'id_type'      => 'PASSPORT',
        ]);

        expect($session['country_code'])->toBe('GH');
        expect($session['id_type'])->toBe('PASSPORT');
    });

    it('returns a fail-closed result shape, never a pass', function () {
        $result = $this->service->getVerificationResult('smileid', 'smileid_abc123');

        // A simulated result must never look like a verification: no
        // passing status, no fabricated confidence, no invented identity.
        expect($result['status'])->toBe('requires_manual_review');
        expect($result['verified'])->toBeFalse();
        expect($result['sandbox'])->toBeTrue();
        expect($result['confidence'])->toBe(0.0);
        expect($result['checks'])->toBe([
            'document_validity'  => false,
            'face_match'         => false,
            'liveness_check'     => false,
            'id_authority_check' => false,
        ]);
        expect($result)->not->toHaveKey('extracted_data');
        expect($result['smile_job_id'])->toBeNull();
        expect($result['result_code'])->toBeNull();
    });

    it('throws InvalidArgumentException for unknown provider', function () {
        $this->service->createVerificationSession('unknown_provider', []);
    })->throws(InvalidArgumentException::class);

    it('throws InvalidArgumentException for unknown provider on result', function () {
        $this->service->getVerificationResult('unknown_provider', 'some_session');
    })->throws(InvalidArgumentException::class);
});
