<?php

declare(strict_types=1);

use App\Domain\KeyManagement\HSM\DemoHsmProvider;
use App\Domain\KeyManagement\HSM\HsmProviderFactory;

it('has no hardcoded demo key in source', function () {
    $source = (string) file_get_contents(app_path('Domain/KeyManagement/HSM/DemoHsmProvider.php'));

    expect($source)->not->toContain('demo-hsm-encryption-key-32bytes!');
});

it('encrypts and decrypts within the process without a hardcoded key', function () {
    $encrypting = new DemoHsmProvider();
    $ciphertext = $encrypting->encrypt('custodial-test-material', 'test-key');

    $decrypting = new DemoHsmProvider();

    expect($decrypting->decrypt($ciphertext, 'test-key'))->toBe('custodial-test-material')
        ->and($ciphertext)->not->toContain('custodial-test-material');
});

it('refuses to construct the demo HSM when demo mode is off', function () {
    config(['keymanagement.demo_mode' => false]);

    expect(fn () => new DemoHsmProvider())->toThrow(RuntimeException::class);
    expect(fn () => (new HsmProviderFactory())->create('demo'))->toThrow(RuntimeException::class);
});
