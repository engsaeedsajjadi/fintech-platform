<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->operator = User::factory()->create();
    $this->operator->assignRole('admin');
});

function intlApiAuth(User $user): void
{
    Sanctum::actingAs($user, ['read', 'write', 'delete']);
}

it('creates an international account and lists it with a ledger-derived balance', function (): void {
    intlApiAuth($this->user);

    $response = $this->postJson('/api/v1/international/accounts', ['currency' => 'USD', 'nickname' => 'Dollars']);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.balance', '0.0000');

    $this->getJson('/api/v1/international/accounts')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('requires an idempotency key to create a transfer', function (): void {
    intlApiAuth($this->user);

    $this->postJson('/api/v1/international/transfers', [
        'international_account_id' => 'x',
        'counterparty_id'          => 'x',
        'beneficiary_id'           => 'x',
        'amount_minor'             => 100,
        'currency'                 => 'USD',
        'destination_currency'     => 'USD',
        'purpose'                  => 'family_support',
        'rail'                     => 'swift',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'MISSING_IDEMPOTENCY_KEY');
});

it('creates a transfer, hides it from other users, and walks the manual screening flow', function (): void {
    intlApiAuth($this->user);

    $account = $this->postJson('/api/v1/international/accounts', ['currency' => 'USD'])->json('data');

    $counterparty = $this->postJson('/api/v1/international/counterparties', [
        'type' => 'individual', 'full_name' => 'Jane Example', 'country' => 'DE',
    ])->assertCreated()->json('data');

    $beneficiary = $this->postJson("/api/v1/international/counterparties/{$counterparty['id']}/beneficiaries", [
        'display_name' => 'Jane Example', 'country' => 'DE', 'currency' => 'USD', 'iban_last4' => '1234',
    ])->assertCreated()->json('data');

    $transfer = $this->withHeader('Idempotency-Key', 'api-key-1')
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

    expect($transfer['status'])->toBe('draft')
        ->and($transfer['rail_enabled'])->toBeFalse();

    // Another user cannot see it.
    intlApiAuth(User::factory()->create());
    $this->getJson("/api/v1/international/transfers/{$transfer['id']}")->assertForbidden();

    // Owner submits; a non-operator cannot start screening.
    intlApiAuth($this->user);
    $this->postJson("/api/v1/international/transfers/{$transfer['id']}/submit")->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    $this->postJson("/api/v1/international/transfers/{$transfer['id']}/screening")->assertForbidden();

    // Operator starts screening: manual-review default keeps it in screening.
    intlApiAuth($this->operator);
    $this->postJson("/api/v1/international/transfers/{$transfer['id']}/screening")->assertOk()
        ->assertJsonPath('data.status', 'screening');

    $this->postJson("/api/v1/international/transfers/{$transfer['id']}/screening-decision", [
        'decision' => 'clear', 'reason' => 'documents reviewed',
    ])->assertOk()
        ->assertJsonPath('data.status', 'approved');

    // History shows the full trail.
    intlApiAuth($this->user);
    $this->getJson("/api/v1/international/transfers/{$transfer['id']}/history")->assertOk()
        ->assertJsonPath('data.transfer.status', 'approved')
        ->assertJsonCount(4, 'data.status_history');
});

it('fails external dispatch closed through the API and returns the funds', function (): void {
    intlApiAuth($this->user);

    $account = $this->postJson('/api/v1/international/accounts', ['currency' => 'USD'])->json('data');

    $counterparty = $this->postJson('/api/v1/international/counterparties', [
        'type' => 'individual', 'full_name' => 'Jane Example', 'country' => 'DE',
    ])->json('data');

    $beneficiary = $this->postJson("/api/v1/international/counterparties/{$counterparty['id']}/beneficiaries", [
        'display_name' => 'Jane Example', 'country' => 'DE', 'currency' => 'USD',
    ])->json('data');

    // Fund the sender account directly in the ledger (test-only funding).
    $ledger = new App\Domain\Ledger\Services\LedgerService(new App\Domain\Ledger\Services\Drivers\EloquentDriver());
    App\Domain\Ledger\Models\LedgerAccount::firstOrCreate(
        ['code' => 'intl:funding:USD'],
        ['name' => 'Test funding', 'type' => 'asset', 'currency' => 'USD'],
    );
    $ledger->post('Test funding', [
        ['account_code' => 'intl:funding:USD', 'debit' => '1000.0000', 'credit' => '0.0000', 'currency' => 'USD'],
        ['account_code' => $account['ledger_account_code'], 'debit' => '0.0000', 'credit' => '1000.0000', 'currency' => 'USD'],
    ]);

    $transfer = $this->withHeader('Idempotency-Key', 'api-key-2')
        ->postJson('/api/v1/international/transfers', [
            'international_account_id' => $account['id'],
            'counterparty_id'          => $counterparty['id'],
            'beneficiary_id'           => $beneficiary['id'],
            'amount_minor'             => 10000,
            'currency'                 => 'USD',
            'destination_currency'     => 'USD',
            'purpose'                  => 'family_support',
            'rail'                     => 'swift',
        ])->json('data');

    $this->postJson("/api/v1/international/transfers/{$transfer['id']}/submit")->assertOk();

    intlApiAuth($this->operator);
    $this->postJson("/api/v1/international/transfers/{$transfer['id']}/screening")->assertOk();
    $this->postJson("/api/v1/international/transfers/{$transfer['id']}/screening-decision", [
        'decision' => 'clear', 'reason' => 'ok',
    ])->assertOk();

    intlApiAuth($this->user);
    $this->postJson("/api/v1/international/transfers/{$transfer['id']}/process")->assertOk()
        ->assertJsonPath('data.status', 'processing');

    // Dispatch is refused by the disabled gateway: 422 + transfer failed.
    intlApiAuth($this->operator);
    $this->postJson("/api/v1/international/transfers/{$transfer['id']}/dispatch")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'RAIL_NOT_AVAILABLE')
        ->assertJsonPath('data.status', 'failed');

    // And the sender's balance is whole again.
    intlApiAuth($this->user);
    $this->getJson("/api/v1/international/accounts/{$account['id']}")->assertOk()
        ->assertJsonPath('data.balance', '1000.0000');
});
