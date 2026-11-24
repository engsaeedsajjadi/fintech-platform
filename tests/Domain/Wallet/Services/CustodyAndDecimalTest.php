<?php

declare(strict_types=1);

use App\Domain\KeyManagement\Exceptions\HsmNotConfiguredException;
use App\Domain\KeyManagement\Services\CustodialKeyGate;
use App\Domain\Wallet\Services\WalletTransferService;

describe('CustodialKeyGate', function () {
    it('fails closed when custodial storage is not enabled', function () {
        config(['keymanagement.custodial.enabled' => false]);

        expect(fn () => CustodialKeyGate::requireHsm())->toThrow(HsmNotConfiguredException::class);
        expect(CustodialKeyGate::isHsmConfigured())->toBeFalse();
    });

    it('fails closed when the HSM provider is demo', function () {
        config([
            'keymanagement.custodial.enabled'           => true,
            'keymanagement.hsm.provider'                => 'demo',
            'keymanagement.custodial.hsm_key_reference' => 'demo-key',
        ]);

        expect(fn () => CustodialKeyGate::requireHsm())->toThrow(HsmNotConfiguredException::class);
    });

    it('fails closed when the HSM key reference is missing', function () {
        config([
            'keymanagement.custodial.enabled'           => true,
            'keymanagement.hsm.provider'                => 'aws',
            'keymanagement.custodial.hsm_key_reference' => '',
        ]);

        expect(fn () => CustodialKeyGate::requireHsm())->toThrow(HsmNotConfiguredException::class);
    });

    it('passes only with a real HSM provider, enabled custodial storage and a key reference', function () {
        config([
            'keymanagement.custodial.enabled'           => true,
            'keymanagement.hsm.provider'                => 'aws',
            'keymanagement.custodial.hsm_key_reference' => 'arn:aws:kms:us-east-1:123456789:key/test',
        ]);

        expect(CustodialKeyGate::requireHsm())->toBe('arn:aws:kms:us-east-1:123456789:key/test')
            ->and(CustodialKeyGate::isHsmConfigured())->toBeTrue();
    });
});

describe('WalletTransferService decimal quotes', function () {
    it('computes the transaction total with decimal strings, never float addition', function () {
        $service = new WalletTransferService();
        $address = '0x' . str_repeat('a', 40);

        $quote = $service->getTransactionQuote($address, 'polygon', 'USDC', '100.000001');

        // Polygon gas estimate is 0.01 USD; 100.000001 + 0.010000 = 100.010001 exactly.
        expect($quote['total'])->toBe('100.010001')
            ->and($quote['fee'])->toBe('0.010000');
    });
});
