<?php

declare(strict_types=1);

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Models\Cardholder;
use App\Domain\CardIssuance\Services\SpendLimitEnforcementService;
use App\Models\User;

function spendLimitCard(int $limitCents): Card
{
    $user = User::factory()->create();
    $cardholder = Cardholder::create([
        'user_id'    => $user->id,
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
    ]);

    return Card::create([
        'user_id'              => $user->id,
        'cardholder_id'        => $cardholder->id,
        'issuer_card_token'    => 'tok-spend-' . uniqid(),
        'issuer'               => 'demo',
        'last4'                => '4242',
        'network'              => 'visa',
        'status'               => 'active',
        'currency'             => 'USD',
        'spend_limit_cents'    => $limitCents,
        'spend_limit_interval' => 'daily',
    ]);
}

it('converts a decimal amount to minor units exactly: 19.99 is 1999 cents, not 1998', function (): void {
    $service = app(SpendLimitEnforcementService::class);
    $card = spendLimitCard(2000);

    // 19.99 USD against a 20.00 limit must pass; (int)(19.99 * 100) is
    // 1998 in binary floating point, which would wrongly pass a 1999-cent
    // check — the exact conversion must yield 1999.
    expect($service->checkLimitCents($card->issuer_card_token, 1999))->toBeTrue()
        ->and($service->checkLimit($card->issuer_card_token, '19.99'))->toBeTrue();

    $service->recordSpendCents($card->issuer_card_token, 1999);

    // One more cent lands exactly on the 2000-cent limit (allowed);
    // two more cents exceed it.
    expect($service->checkLimitCents($card->issuer_card_token, 1))->toBeTrue()
        ->and($service->checkLimitCents($card->issuer_card_token, 2))->toBeFalse();
});

it('rejects a spend that exceeds the limit when computed in minor units', function (): void {
    $service = app(SpendLimitEnforcementService::class);
    $card = spendLimitCard(1000);

    expect($service->checkLimit($card->issuer_card_token, '10.01'))->toBeFalse()
        ->and($service->checkLimitCents($card->issuer_card_token, 1001))->toBeFalse()
        ->and($service->checkLimitCents($card->issuer_card_token, 1000))->toBeTrue();
});
