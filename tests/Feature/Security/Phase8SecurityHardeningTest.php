<?php

declare(strict_types=1);

/**
 * Phase 8 — Security hardening tests.
 *
 * Covers the fixes made in the Phase 8 review:
 *  - SafeDeal authorization: dispute resolution is admin-only, document
 *    review and risk assessment are party-or-admin, and counterparty
 *    verification is bound to approved platform KYC (no self-asserted
 *    "fake KYC" reference can flip a party to VERIFIED).
 *  - International cross-user access (transfers, accounts, quotations)
 *    and idempotency replay.
 *  - Webhook spoofing / replay (incl. bare signatures without `t=`).
 *  - Disabled-provider execution in production (provider registry,
 *    KYC gate, demo-mode guard, sandbox exchange rates, payment
 *    service binding).
 *  - Production demo flags (DEMO_SKIP_KYC / DEMO_AUTO_APPROVE): safe
 *    defaults when unset, preflight FAIL when explicitly enabled.
 *  - `key:generate` boot failure (RotateWalletKeys eager resolution).
 *  - Filament UserResource no longer exposes 2FA secrets.
 *  - Unauthenticated access + non-admin admin-panel access.
 */

use App\Domain\Asset\Services\ExchangeRateService;
use App\Domain\Banking\Connectors\SandboxBankAdapter;
use App\Domain\Banking\Providers\Exceptions\ProviderUnavailableException;
use App\Domain\Banking\Providers\Support\ProviderRegistry;
use App\Domain\Banking\Providers\Support\WebhookVerifier;
use App\Domain\Compliance\Services\KycVerificationGate;
use App\Domain\Exchange\Services\EnhancedExchangeRateService;
use App\Domain\Exchange\Services\ExchangeRateProviderRegistry;
use App\Domain\Ledger\Services\DemoModeGuard;
use App\Domain\Payment\Contracts\PaymentServiceInterface;
use App\Domain\Payment\Services\ProductionPaymentService;
use App\Domain\Payment\Services\SandboxPaymentService;
use App\Domain\SafeDeal\Enums\DisputeResolution;
use App\Domain\SafeDeal\Exceptions\SafeDealException;
use App\Domain\SafeDeal\Services\SafeDealService;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use App\Providers\ExchangeRateProviderServiceProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

function phase8Sanctum(User $user): void
{
    Sanctum::actingAs($user, ['read', 'write', 'delete']);
}

/** Create a buyer/seller pair with approved enhanced platform KYC. */
function phase8KycUsers(): array
{
    $buyer = User::factory()->create(['kyc_status' => 'approved', 'kyc_level' => 'enhanced']);
    $seller = User::factory()->create(['kyc_status' => 'approved', 'kyc_level' => 'enhanced']);

    return [$buyer, $seller];
}

/** @return array{0: App\Domain\SafeDeal\Models\SafeDealTrade, 1: User, 2: User} */
function phase8CreateTrade(string $amount = '100.0000'): array
{
    [$buyer, $seller] = phase8KycUsers();

    $trade = app(SafeDealService::class)->createTrade(
        (string) $buyer->id,
        (string) $seller->id,
        $amount,
        'USD',
        [
            'description'       => 'Phase 8 test trade',
            'conditions'        => ['required_documents' => ['agreement_copy']],
            'delivery_deadline' => now()->addDays(30)->toDateString(),
        ],
        [['title' => 'Delivery', 'amount' => $amount]],
    );

    return [$trade, $buyer, $seller];
}

/** Drive a trade to FUNDED and open a dispute; returns [trade, buyer, seller, dispute]. */
function phase8FundedTradeWithDispute(): array
{
    [$trade, $buyer, $seller] = phase8CreateTrade();
    $service = app(SafeDealService::class);
    $id = $trade->trade_id;

    $service->verifyCounterparty($id, (string) $buyer->id, 'kyc-buyer-phase8', 'enhanced');
    $service->verifyCounterparty($id, (string) $seller->id, 'kyc-seller-phase8', 'enhanced');
    $service->submitAgreement($id, (string) $buyer->id);
    $service->acceptAgreement($id, (string) $seller->id);
    $document = $service->submitDocument($id, (string) $buyer->id, 'agreement_copy', 'doc-phase8-1');
    $service->reviewDocument($id, $document->id, (string) $seller->id, true);
    $service->assessRisk($id, (string) $seller->id);
    $service->authorizeFunding($id, (string) $buyer->id);
    $service->confirmPayment($id, (string) $buyer->id);

    $dispute = $service->openDispute($id, (string) $buyer->id, 'Goods not as described');

    return [$service->getTrade($id), $buyer, $seller, $dispute];
}

/**
 * Evaluate config/demo.php with the given env vars set (null = unset),
 * as `config:cache` would, and restore the environment afterwards.
 *
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function phase8EvalDemoConfig(array $env): array
{
    $previous = [];
    foreach ($env as $key => $value) {
        $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        } else {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require base_path('config/demo.php');

        return $config;
    } finally {
        foreach ($previous as $key => [$server, $envValue, $getenv]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            if ($getenv === false) {
                putenv($key);
            } else {
                putenv("{$key}={$getenv}");
            }
        }
    }
}

/** Run ops:verify-env --json and return one check by name. */
function phase8OpsCheck(string $name): array
{
    Artisan::call('ops:verify-env', ['--json' => true]);

    /** @var array{checks: list<array{name: string, category: string, result: string, detail: string}>} $decoded */
    $decoded = json_decode(Artisan::output(), true);

    return collect($decoded['checks'])->firstWhere('name', $name)
        ?? ['name' => $name, 'category' => '', 'result' => 'MISSING', 'detail' => ''];
}

describe('SafeDeal authorization (Phase 8)', function (): void {
    it('refuses dispute resolution to a party and to a stranger, and allows an admin', function (): void {
        [$trade, $buyer, $seller, $dispute] = phase8FundedTradeWithDispute();
        $stranger = User::factory()->create();

        // A party (the buyer who opened the dispute) may not resolve it.
        phase8Sanctum($buyer);
        $this->postJson(
            "/api/v1/safedeal/trades/{$trade->trade_id}/disputes/{$dispute->dispute_id}/resolve",
            ['resolution' => 'return_to_buyer'],
        )->assertForbidden();

        // A stranger may not resolve it either.
        phase8Sanctum($stranger);
        $this->postJson(
            "/api/v1/safedeal/trades/{$trade->trade_id}/disputes/{$dispute->dispute_id}/resolve",
            ['resolution' => 'return_to_buyer'],
        )->assertForbidden();

        // An admin operator can.
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        phase8Sanctum($admin);
        $this->postJson(
            "/api/v1/safedeal/trades/{$trade->trade_id}/disputes/{$dispute->dispute_id}/resolve",
            ['resolution' => 'return_to_buyer'],
        )->assertOk()->assertJsonPath('data.status', 'refunded');
    });

    it('refuses dispute resolution at the service layer even without HTTP', function (): void {
        [$trade, $buyer] = phase8CreateTrade();

        expect(fn () => app(SafeDealService::class)->resolveDispute(
            $trade->trade_id,
            'missing-dispute',
            (string) $buyer->id,
            DisputeResolution::RELEASE_TO_SELLER,
        ))->toThrow(SafeDealException::class, 'Only an admin operator can resolve a dispute.');
    });

    it('refuses document review and risk assessment to strangers, allows a party', function (): void {
        [$trade, $buyer, $seller] = phase8CreateTrade();
        $service = app(SafeDealService::class);
        $stranger = User::factory()->create();

        $service->verifyCounterparty($trade->trade_id, (string) $buyer->id, 'kyc-buyer-phase8', 'enhanced');
        $service->verifyCounterparty($trade->trade_id, (string) $seller->id, 'kyc-seller-phase8', 'enhanced');
        $service->submitAgreement($trade->trade_id, (string) $buyer->id);
        $service->acceptAgreement($trade->trade_id, (string) $seller->id);
        $document = $service->submitDocument($trade->trade_id, (string) $buyer->id, 'agreement_copy', 'doc-phase8-2');

        phase8Sanctum($stranger);
        $this->postJson(
            "/api/v1/safedeal/trades/{$trade->trade_id}/documents/{$document->id}/review",
            ['approve' => true],
        )->assertForbidden();
        $this->postJson("/api/v1/safedeal/trades/{$trade->trade_id}/risk-assessment")->assertForbidden();

        // The seller (a counterparty) may review and assess.
        phase8Sanctum($seller);
        $this->postJson(
            "/api/v1/safedeal/trades/{$trade->trade_id}/documents/{$document->id}/review",
            ['approve' => true],
        )->assertOk();
        $this->postJson("/api/v1/safedeal/trades/{$trade->trade_id}/risk-assessment")->assertOk();
    });
});

describe('SafeDeal fake-KYC attempts (Phase 8)', function (): void {
    it('refuses verification for a user whose platform KYC is not approved', function (): void {
        [$trade, $buyer] = phase8CreateTrade();
        // Buyer/seller fixtures above carry approved KYC; use a fresh
        // unapproved user as the counterparty instead.
        $unapproved = User::factory()->create(['kyc_status' => 'not_started']);
        $trade2 = app(SafeDealService::class)->createTrade(
            (string) $unapproved->id,
            (string) $buyer->id,
            '50.0000',
            'USD',
            ['description' => 'Unapproved buyer trade', 'conditions' => ['required_documents' => ['agreement_copy']], 'delivery_deadline' => now()->addDays(10)->toDateString()],
            [['title' => 'Delivery', 'amount' => '50.0000']],
        );

        expect(fn () => app(SafeDealService::class)->verifyCounterparty(
            $trade2->trade_id,
            (string) $unapproved->id,
            'totally-fake-reference-123',
            'enhanced',
        ))->toThrow(SafeDealException::class, 'platform KYC is not approved');

        // The trade stays unverified.
        expect(app(SafeDealService::class)->getTrade($trade2->trade_id)->buyer_verification->value)
            ->not->toBe('verified');
    });

    it('refuses enhanced verification when platform KYC level is only basic', function (): void {
        [$trade, $buyer, $seller] = phase8CreateTrade();
        $basic = User::factory()->create(['kyc_status' => 'approved', 'kyc_level' => 'basic']);
        $trade2 = app(SafeDealService::class)->createTrade(
            (string) $basic->id,
            (string) $seller->id,
            '50.0000',
            'USD',
            ['description' => 'Basic-level buyer trade', 'conditions' => ['required_documents' => ['agreement_copy']], 'delivery_deadline' => now()->addDays(10)->toDateString()],
            [['title' => 'Delivery', 'amount' => '50.0000']],
        );

        expect(fn () => app(SafeDealService::class)->verifyCounterparty(
            $trade2->trade_id,
            (string) $basic->id,
            'kyc-basic-user',
            'enhanced',
        ))->toThrow(SafeDealException::class, "level 'enhanced'");
    });

    it('accepts verification for a user with approved enhanced platform KYC', function (): void {
        [$trade, $buyer] = phase8CreateTrade();

        $verified = app(SafeDealService::class)->verifyCounterparty(
            $trade->trade_id,
            (string) $buyer->id,
            'kyc-real-reference',
            'enhanced',
        );

        expect($verified->buyer_verification->value)->toBe('verified');
    });
});

describe('International cross-user access and idempotency replay (Phase 8)', function (): void {
    it('hides transfers, accounts, counterparties and quotations from other users', function (): void {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        phase8Sanctum($owner);
        $account = $this->postJson('/api/v1/international/accounts', ['currency' => 'USD'])->assertCreated()->json('data');
        $counterparty = $this->postJson('/api/v1/international/counterparties', [
            'type' => 'individual', 'full_name' => 'Jane Example', 'country' => 'DE',
        ])->assertCreated()->json('data');
        $beneficiary = $this->postJson("/api/v1/international/counterparties/{$counterparty['id']}/beneficiaries", [
            'display_name' => 'Jane Example', 'country' => 'DE', 'currency' => 'USD', 'iban_last4' => '1234',
        ])->assertCreated()->json('data');
        $quotation = $this->postJson('/api/v1/international/quotations', [
            'source_currency' => 'USD', 'destination_currency' => 'USD', 'amount_minor' => 10000, 'rate' => '1.0000',
        ])->assertCreated()->json('data');
        $transfer = $this->withHeader('Idempotency-Key', 'phase8-xuser-1')
            ->postJson('/api/v1/international/transfers', [
                'international_account_id' => $account['id'],
                'counterparty_id'          => $counterparty['id'],
                'beneficiary_id'           => $beneficiary['id'],
                'amount_minor'             => 10000,
                'currency'                 => 'USD',
                'destination_currency'     => 'USD',
                'purpose'                  => 'family_support',
                'rail'                     => 'swift',
            ])->assertCreated()->json('data');

        phase8Sanctum($other);
        $this->getJson("/api/v1/international/transfers/{$transfer['id']}")->assertForbidden();
        $this->postJson("/api/v1/international/transfers/{$transfer['id']}/submit")->assertForbidden();
        $this->postJson("/api/v1/international/transfers/{$transfer['id']}/reverse", ['reason' => 'nope'])->assertForbidden();
        $this->getJson("/api/v1/international/transfers/{$transfer['id']}/history")->assertForbidden();
        $this->getJson("/api/v1/international/accounts/{$account['id']}")->assertNotFound();
        $this->getJson("/api/v1/international/counterparties/{$counterparty['id']}")->assertNotFound();
        $this->postJson("/api/v1/international/quotations/{$quotation['id']}/accept")->assertNotFound();

        // And the other user's list contains none of the owner's transfers.
        $this->getJson('/api/v1/international/transfers')->assertOk()->assertJsonCount(0, 'data.data');
    });

    it('rejects an idempotency-key replay with a different payload and replays the same payload', function (): void {
        $owner = User::factory()->create();
        phase8Sanctum($owner);

        $account = $this->postJson('/api/v1/international/accounts', ['currency' => 'USD'])->assertCreated()->json('data');
        $counterparty = $this->postJson('/api/v1/international/counterparties', [
            'type' => 'individual', 'full_name' => 'Jane Example', 'country' => 'DE',
        ])->assertCreated()->json('data');
        $beneficiary = $this->postJson("/api/v1/international/counterparties/{$counterparty['id']}/beneficiaries", [
            'display_name' => 'Jane Example', 'country' => 'DE', 'currency' => 'USD', 'iban_last4' => '1234',
        ])->assertCreated()->json('data');

        $payload = [
            'international_account_id' => $account['id'],
            'counterparty_id'          => $counterparty['id'],
            'beneficiary_id'           => $beneficiary['id'],
            'amount_minor'             => 10000,
            'currency'                 => 'USD',
            'destination_currency'     => 'USD',
            'purpose'                  => 'family_support',
            'rail'                     => 'swift',
        ];

        $first = $this->withHeader('Idempotency-Key', 'phase8-replay-1')
            ->postJson('/api/v1/international/transfers', $payload)->assertCreated()->json('data');

        // Same key, same payload: the original transfer is returned, no duplicate.
        $replay = $this->withHeader('Idempotency-Key', 'phase8-replay-1')
            ->postJson('/api/v1/international/transfers', $payload)->assertCreated()->json('data');
        expect($replay['id'])->toBe($first['id']);

        // Same key, different payload: conflict, no second transfer.
        $this->withHeader('Idempotency-Key', 'phase8-replay-1')
            ->postJson('/api/v1/international/transfers', array_merge($payload, ['amount_minor' => 99999]))
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    });

    it('requires authentication on international and safedeal endpoints', function (): void {
        $this->getJson('/api/v1/international/accounts')->assertUnauthorized();
        $this->postJson('/api/v1/safedeal/trades', [])->assertUnauthorized();
        $this->getJson('/api/v1/international/transfers')->assertUnauthorized();
    });
});

describe('Webhook spoofing and replay (Phase 8)', function (): void {
    it('rejects a replayed bare signature, a tampered body and an empty secret', function (): void {
        config(['providers.webhook.tolerance_seconds' => 300]);
        $verifier = new WebhookVerifier();
        $secret = 'whsec-phase8-secret';
        $body = '{"type":"payment.succeeded","id":"evt_phase8"}';

        // Bare signature (no `t=` timestamp): valid exactly once.
        $bare = hash_hmac('sha256', $body, $secret);
        expect($verifier->verify($body, $bare, $secret))->toBeTrue()
            ->and($verifier->verify($body, $bare, $secret))->toBeFalse();

        // Tampered body with the original signature.
        expect($verifier->verify('{"type":"payment.succeeded","id":"evt_other"}', $bare, $secret))->toBeFalse();

        // Spoofed signature and empty secret.
        expect($verifier->verify($body, hash_hmac('sha256', $body, 'attacker-secret'), $secret))->toBeFalse()
            ->and($verifier->verify($body, $bare, ''))->toBeFalse();
    });
});

describe('Disabled providers stay disabled in production (Phase 8)', function (): void {
    it('refuses to register a sandbox provider when sandbox mode is disallowed', function (): void {
        $registry = new ProviderRegistry(sandboxAllowed: false);

        expect(fn () => $registry->register('sandbox', new SandboxBankAdapter([])))
            ->toThrow(ProviderUnavailableException::class);
    });

    it('refuses simulated KYC providers in production even when the flag is on', function (): void {
        app()->detectEnvironment(fn () => 'production');
        config(['kyc.verification.simulated_providers_enabled' => true]);

        expect(app(KycVerificationGate::class)->simulatedProvidersAllowed())->toBeFalse();
    });

    it('refuses simulated money paths in production via the demo-mode guard', function (): void {
        app()->detectEnvironment(fn () => 'production');
        config(['payment_rails.simulation.enabled' => true]);

        expect(fn () => DemoModeGuard::assertSimulationAllowed('phase8-test'))->toThrow(RuntimeException::class);
    });

    it('refuses sandbox exchange rates in production even when the flag is on', function (): void {
        app()->detectEnvironment(fn () => 'production');
        config(['asset.sandbox_rates_enabled' => true]);

        // The parent service directly: its hardcoded sandbox tables must
        // never be consulted in production.
        expect((new ExchangeRateService())->fetchAndStoreRate('USD', 'EUR'))->toBeNull();
    });

    it('does not register the mock exchange-rate provider in production', function (): void {
        app()->detectEnvironment(fn () => 'production');
        config(['exchange.providers.mock.enabled' => true]);

        // The app booted in testing (mock registered); rebuild the
        // registry through the provider boot under a production
        // environment to prove the gate.
        app()->forgetInstance(ExchangeRateProviderRegistry::class);
        app()->forgetInstance(EnhancedExchangeRateService::class);
        app()->forgetInstance(ExchangeRateService::class);
        (new ExchangeRateProviderServiceProvider(app()))->boot();

        expect(app(ExchangeRateProviderRegistry::class)->has('mock'))->toBeFalse()
            // With no provider at all, the enhanced service fails closed.
            ->and(app(ExchangeRateService::class)->fetchAndStoreRate('USD', 'EUR'))->toBeNull();
    });

    it('binds the production payment service in production even with SANDBOX_MODE on', function (): void {
        app()->detectEnvironment(fn () => 'production');
        config(['demo.sandbox.enabled' => true]);
        // force=true: the provider already ran at boot; re-running
        // register() is what re-evaluates the binding.
        app()->register(App\Providers\DemoServiceProvider::class, true);

        expect(app(PaymentServiceInterface::class))->toBeInstanceOf(ProductionPaymentService::class);
    });

    it('binds the sandbox payment service outside production when SANDBOX_MODE is on', function (): void {
        config(['demo.sandbox.enabled' => true]);
        app()->register(App\Providers\DemoServiceProvider::class, true);

        expect(app(PaymentServiceInterface::class))->toBeInstanceOf(SandboxPaymentService::class);
    });
});

describe('Production demo flags (Phase 8)', function (): void {
    it('defaults DEMO_SKIP_KYC and DEMO_AUTO_APPROVE to false under APP_ENV=production when unset', function (): void {
        $config = phase8EvalDemoConfig([
            'APP_ENV'           => 'production',
            'DEMO_SKIP_KYC'     => null,
            'DEMO_AUTO_APPROVE' => null,
        ]);

        expect($config['features']['skip_kyc'])->toBeFalse()
            ->and($config['features']['auto_approve'])->toBeFalse();
    });

    it('reads an explicit DEMO_SKIP_KYC=true in production (and the preflight refuses it)', function (): void {
        $config = phase8EvalDemoConfig([
            'APP_ENV'       => 'production',
            'DEMO_SKIP_KYC' => 'true',
        ]);
        expect($config['features']['skip_kyc'])->toBeTrue();

        app()->detectEnvironment(fn () => 'production');
        config(['demo.features' => [
            'instant_deposits'     => false,
            'skip_kyc'             => true,
            'mock_external_apis'   => false,
            'fixed_exchange_rates' => false,
            'auto_approve'         => true,
        ]]);

        $exitCode = Artisan::call('ops:verify-env', ['--json' => true]);
        $check = phase8OpsCheck('demo.features');

        expect($exitCode)->toBe(1)
            ->and($check['result'])->toBe('FAIL');
    });

    it('fails the preflight when any simulation opt-in is explicitly enabled', function (): void {
        app()->detectEnvironment(fn () => 'production');

        config(['asset.sandbox_rates_enabled' => true]);
        expect(phase8OpsCheck('asset.sandbox_rates_enabled')['result'])->toBe('FAIL');

        config(['asset.sandbox_rates_enabled' => false, 'exchange.providers.mock.enabled' => true]);
        expect(phase8OpsCheck('exchange.providers.mock.enabled')['result'])->toBe('FAIL');

        config(['exchange.providers.mock.enabled' => false, 'kyc.verification.simulated_providers_enabled' => true]);
        expect(phase8OpsCheck('kyc.verification.simulated_providers_enabled')['result'])->toBe('FAIL');

        config(['kyc.verification.simulated_providers_enabled' => false, 'payment_rails.simulation.enabled' => true]);
        expect(phase8OpsCheck('payment_rails.simulation.enabled')['result'])->toBe('FAIL');
    });

    it('fails the preflight when a localhost CORS origin is allowed in production', function (): void {
        app()->detectEnvironment(fn () => 'production');
        config(['cors.allowed_origins' => ['https://app.finaegis.org', 'http://localhost:3000']]);

        expect(phase8OpsCheck('cors.local_origins')['result'])->toBe('FAIL');
    });
});

describe('key:generate boot failure (Phase 8)', function (): void {
    it('runs key:generate --show with no APP_KEY set', function (): void {
        $process = new Process(
            ['php', 'artisan', 'key:generate', '--show'],
            base_path(),
            ['APP_KEY' => ''],
        );
        $process->setTimeout(120);
        $process->run();

        expect($process->isSuccessful())->toBeTrue()
            ->and(trim($process->getOutput()))->toMatch('/^base64:[A-Za-z0-9+\/=]{43}=$/');
    });

    it('runs other artisan commands with no APP_KEY set', function (): void {
        $process = new Process(['php', 'artisan', '--version'], base_path(), ['APP_KEY' => '']);
        $process->setTimeout(120);
        $process->run();

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getOutput())->toContain('Laravel Framework');
    });
});

describe('Filament UserResource 2FA exposure (Phase 8)', function (): void {
    it('does not render two_factor_secret or two_factor_recovery_codes on the edit page', function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setServingStatus(true);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $user = User::factory()->create([
            'two_factor_secret'         => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
            'two_factor_confirmed_at'   => now(),
        ]);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertOk()
            ->assertDontSee('two_factor_secret')
            ->assertDontSee('two_factor_recovery_codes');
    });
});

describe('Admin panel role boundary (Phase 8)', function (): void {
    it('redirects a non-admin user away from /admin', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/admin')->assertRedirect('/dashboard');
    });

    it('lets an admin user open /admin', function (): void {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get('/admin')->assertOk();
    });
});
