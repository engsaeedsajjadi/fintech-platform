<?php

declare(strict_types=1);

use App\Domain\CardIssuance\Adapters\DemoCardIssuerAdapter;
use App\Domain\CardIssuance\Adapters\FinCardCardIssuerAdapter;
use App\Domain\CardIssuance\Adapters\RainCardIssuerAdapter;
use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\Enums\CardNetwork;
use App\Domain\CardIssuance\Enums\CardStatus;
use App\Domain\CardIssuance\Enums\WalletType;
use App\Domain\CardIssuance\Events\CardReplaced;
use App\Domain\CardIssuance\Exceptions\UnsupportedCardOperationException;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Models\CardFundingAccount;
use App\Domain\CardIssuance\Models\CardIssuanceAttempt;
use App\Domain\CardIssuance\Models\Cardholder;
use App\Domain\CardIssuance\Models\FinCardAccount;
use App\Domain\CardIssuance\Services\CardLifecycleService;
use App\Domain\CardIssuance\Services\CardWebhookService;
use App\Domain\CardIssuance\ValueObjects\CardIssuerCapabilities;
use App\Domain\CardIssuance\ValueObjects\CardTransaction;
use App\Domain\CardIssuance\ValueObjects\ProvisioningData;
use App\Domain\CardIssuance\ValueObjects\VirtualCard;
use App\Infrastructure\FinCard\FinCardClient;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Test double: an issuer whose provider is down. Every createCard call
 * throws, so the lifecycle service must record a manual-review attempt and
 * never report a card as issued.
 */
final class FailingCardIssuer implements CardIssuerInterface
{
    public function getCapabilities(): CardIssuerCapabilities
    {
        return new CardIssuerCapabilities(supportsActivation: true);
    }

    public function activateCard(string $cardToken): bool
    {
        return false;
    }

    public function createCard(
        string $userId,
        string $cardholderName,
        array $metadata = [],
        ?CardNetwork $network = null,
        ?string $label = null,
    ): VirtualCard {
        throw new RuntimeException('provider down');
    }

    public function getProvisioningData(
        string $cardToken,
        WalletType $walletType,
        string $deviceId,
        array $certificates = [],
    ): ProvisioningData {
        throw new RuntimeException('provider down');
    }

    public function freezeCard(string $cardToken): bool
    {
        return false;
    }

    public function unfreezeCard(string $cardToken): bool
    {
        return false;
    }

    public function cancelCard(string $cardToken, string $reason): bool
    {
        return false;
    }

    public function getCard(string $cardToken): ?VirtualCard
    {
        return null;
    }

    public function listUserCards(string $userId): array
    {
        return [];
    }

    public function getTransactions(string $cardToken, int $limit = 20, ?string $cursor = null): array
    {
        return ['transactions' => [], 'next_cursor' => null];
    }

    public function getName(): string
    {
        return 'failing';
    }
}

function demoLifecycle(): CardLifecycleService
{
    return new CardLifecycleService(new DemoCardIssuerAdapter());
}

function lifecycleCard(string $userId, string $token, string $status = 'active', string $issuer = 'demo'): Card
{
    $holder = Cardholder::query()->where('user_id', $userId)->orderBy('id')->first();
    if (! $holder instanceof Cardholder) {
        $holder = new Cardholder();
        $holder->user_id = (int) $userId;
        $holder->first_name = 'Jane';
        $holder->last_name = 'Smith';
        $holder->kyc_status = 'verified';
        $holder->save();
    }

    $card = new Card();
    $card->user_id = $userId;
    $card->cardholder_id = $holder->id;
    $card->issuer_card_token = $token;
    $card->issuer = $issuer;
    $card->last4 = '4242';
    $card->network = 'visa';
    $card->status = $status;
    $card->currency = 'USD';
    $card->save();

    return $card;
}

it('requests a card and persists a local mirror without PAN/CVV', function (): void {
    $user = User::factory()->create();

    $card = demoLifecycle()->requestCard((string) $user->id, 'Jane Smith', [], null, 'My card', 'key-req-1');

    expect($card->status)->toBe(CardStatus::ACTIVE)
        ->and($card->last4)->toHaveLength(4)
        ->and($card->pan)->toBeNull()
        ->and($card->cvv)->toBeNull();

    $mirror = Card::query()->where('issuer_card_token', $card->cardToken)->firstOrFail();
    expect($mirror->status)->toBe('active')
        ->and($mirror->label)->toBe('My card')
        ->and($mirror->merchant_order_no)->toBe('key-req-1');

    expect(CardIssuanceAttempt::query()->where('idempotency_key', 'key-req-1')->value('status'))
        ->toBe(CardIssuanceAttempt::STATUS_SUCCEEDED);
});

it('returns the same card for a duplicate request with the same idempotency key', function (): void {
    $user = User::factory()->create();
    $service = demoLifecycle();

    $first = $service->requestCard((string) $user->id, 'Jane Smith', [], null, null, 'key-dup-1');
    $second = $service->requestCard((string) $user->id, 'Jane Smith', [], null, null, 'key-dup-1');

    expect($second->cardToken)->toBe($first->cardToken)
        ->and(Card::query()->where('user_id', (string) $user->id)->count())->toBe(1)
        ->and(CardIssuanceAttempt::query()->where('idempotency_key', 'key-dup-1')->count())->toBe(1);
});

it('runs the block / unblock / cancel lifecycle with transition validation', function (): void {
    $user = User::factory()->create();
    $service = demoLifecycle();
    $card = $service->requestCard((string) $user->id, 'Jane Smith', [], null, null, 'key-life-1');

    $blocked = $service->blockCard($card->cardToken, 'lost');
    expect($blocked->status)->toBe(CardStatus::FROZEN)
        ->and(Card::query()->where('issuer_card_token', $card->cardToken)->value('status'))->toBe('frozen');

    $unblocked = $service->unblockCard($card->cardToken);
    expect($unblocked->status)->toBe(CardStatus::ACTIVE);

    $cancelled = $service->cancelCard($card->cardToken, 'no longer needed');
    expect($cancelled->status)->toBe(CardStatus::CANCELLED)
        ->and(Card::query()->where('issuer_card_token', $card->cardToken)->value('status'))->toBe('cancelled');
});

it('refuses to block a cancelled card', function (): void {
    $user = User::factory()->create();
    $service = demoLifecycle();
    $card = $service->requestCard((string) $user->id, 'Jane Smith', [], null, null, 'key-life-2');
    $service->cancelCard($card->cardToken, 'done');

    $service->blockCard($card->cardToken);
})->throws(RuntimeException::class, 'Cannot block');

it('replaces a card, cancelling the old one and linking the new one', function (): void {
    Event::fake();
    $user = User::factory()->create();
    $service = demoLifecycle();
    $old = $service->requestCard((string) $user->id, 'Jane Smith', [], null, null, 'key-rep-1');

    $new = $service->replaceCard($old->cardToken, 'damaged', [], 'key-rep-2');

    expect($new->cardToken)->not->toBe($old->cardToken)
        ->and($new->status)->toBe(CardStatus::ACTIVE)
        ->and(Card::query()->where('issuer_card_token', $old->cardToken)->value('status'))->toBe('cancelled');

    Event::assertDispatched(CardReplaced::class);
});

it('activates a pending card through the demo issuer', function (): void {
    $user = User::factory()->create();
    lifecycleCard((string) $user->id, 'card_demo_pending', 'pending');
    Cache::put('card:card_demo_pending', new VirtualCard(
        cardToken: 'card_demo_pending',
        last4: '4242',
        network: CardNetwork::VISA,
        status: CardStatus::PENDING,
        cardholderName: 'Jane Smith',
        expiresAt: new DateTimeImmutable('+3 years'),
    ), now()->addDays(30));

    $activated = demoLifecycle()->activateCard('card_demo_pending');

    expect($activated)->not->toBeNull()
        ->and($activated->status)->toBe(CardStatus::ACTIVE)
        ->and(Card::query()->where('issuer_card_token', 'card_demo_pending')->value('status'))->toBe('active');
});

it('records a failed issuance for manual review instead of a fake success', function (): void {
    $user = User::factory()->create();
    $service = new CardLifecycleService(new FailingCardIssuer());

    $service->requestCard((string) $user->id, 'Jane Smith', [], null, null, 'key-fail-1');
})->throws(RuntimeException::class, 'provider down');

it('leaves a failed attempt in requires_manual_review with no card row', function (): void {
    $user = User::factory()->create();
    $service = new CardLifecycleService(new FailingCardIssuer());

    try {
        $service->requestCard((string) $user->id, 'Jane Smith', [], null, null, 'key-fail-2');
        $this->fail('Expected a provider exception.');
    } catch (RuntimeException) {
        // Expected — the assertions below are the point.
    }

    $attempt = CardIssuanceAttempt::query()->where('idempotency_key', 'key-fail-2')->firstOrFail();
    expect($attempt->status)->toBe(CardIssuanceAttempt::STATUS_REQUIRES_MANUAL_REVIEW)
        ->and($attempt->error)->toContain('provider down')
        ->and(Card::query()->where('user_id', (string) $user->id)->count())->toBe(0);

    $resolved = $service->resolveManualReview((string) $attempt->id, 'reject', 'provider outage, do not retry');
    expect($resolved->status)->toBe(CardIssuanceAttempt::STATUS_FAILED);
});

it('fails delivery tracking explicitly because no issuer supports it', function (): void {
    $user = User::factory()->create();
    $service = demoLifecycle();
    $card = $service->requestCard((string) $user->id, 'Jane Smith', [], null, null, 'key-del-1');

    $service->getDeliveryStatus($card->cardToken);
})->throws(UnsupportedCardOperationException::class, 'does not support delivery tracking');

it('links a card to the owner funding account and refuses another owner account', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $holder = new Cardholder();
    $holder->user_id = (string) $user->id;
    $holder->first_name = 'Jane';
    $holder->last_name = 'Smith';
    $holder->kyc_status = 'verified';
    $holder->save();

    $funding = new CardFundingAccount();
    $funding->user_id = (int) $user->id;
    $funding->cardholder_id = $holder->id;
    $funding->provider = 'demo';
    $funding->provider_account_id = 'fund-1';
    $funding->status = 'active';
    $funding->save();

    $otherFunding = new CardFundingAccount();
    $otherFunding->user_id = (int) $other->id;
    $otherFunding->cardholder_id = $holder->id;
    $otherFunding->provider = 'demo';
    $otherFunding->provider_account_id = 'fund-2';
    $otherFunding->status = 'active';
    $otherFunding->save();

    $service = demoLifecycle();
    $card = $service->requestCard((string) $user->id, 'Jane Smith', [], null, null, 'key-link-1');

    $linked = $service->linkToAccount($card->cardToken, (string) $funding->id);
    expect((string) $linked->funding_account_id)->toBe((string) $funding->id);

    $service->linkToAccount($card->cardToken, (string) $otherFunding->id);
})->throws(InvalidArgumentException::class, 'does not belong to the card owner');

it('resolves the Rain adapter when CARD_ISSUER=rain with credentials', function (): void {
    config([
        'cardissuance.default_issuer' => 'rain',
        'cardissuance.issuers.rain.api_key' => 'test-key',
        'cardissuance.issuers.rain.program_id' => 'prog-1',
    ]);
    $this->app->forgetInstance(CardIssuerInterface::class);

    expect($this->app->make(CardIssuerInterface::class))->toBeInstanceOf(RainCardIssuerAdapter::class);
});

it('fails closed when CARD_ISSUER=rain without credentials', function (): void {
    config([
        'cardissuance.default_issuer' => 'rain',
        'cardissuance.issuers.rain.api_key' => null,
        'cardissuance.issuers.rain.program_id' => null,
    ]);
    $this->app->forgetInstance(CardIssuerInterface::class);

    $this->app->make(CardIssuerInterface::class);
})->throws(RuntimeException::class, 'Rain adapter requires api_key and program_id');

it('exposes provider capability flags', function (): void {
    expect((new DemoCardIssuerAdapter())->getCapabilities()->supportsWalletProvisioning)->toBeTrue()
        ->and((new DemoCardIssuerAdapter())->getCapabilities()->supportsDeliveryTracking)->toBeFalse()
        ->and((new RainCardIssuerAdapter(['api_key' => 'k', 'program_id' => 'p']))->getCapabilities()->supportsSpendLimits)->toBeTrue()
        ->and((new RainCardIssuerAdapter(['api_key' => 'k', 'program_id' => 'p']))->getCapabilities()->supportsPhysicalCards)->toBeFalse();
});

it('processes a Rain card webhook only with a valid HMAC signature', function (): void {
    config(['cardissuance.issuers.rain.webhook_secret' => 's3cr3t']);
    $user = User::factory()->create();
    lifecycleCard((string) $user->id, 'rain-1', 'active', 'rain');
    $service = $this->app->make(CardWebhookService::class);

    $payload = ['event_id' => 'evt-1', 'type' => 'card.status', 'data' => ['card_id' => 'rain-1', 'status' => 'frozen']];
    $rawBody = (string) json_encode($payload);
    $sig = hash_hmac('sha256', $rawBody, 's3cr3t');

    $result = $service->handle('rain', $rawBody, ['X-Rain-Signature' => $sig]);
    expect($result)->toBe(['status' => 'processed', 'http' => 200])
        ->and(Card::query()->where('issuer_card_token', 'rain-1')->value('status'))->toBe('frozen');

    // Identical redelivery is acknowledged as a duplicate, not re-processed.
    expect($service->handle('rain', $rawBody, ['X-Rain-Signature' => $sig]))
        ->toBe(['status' => 'duplicate', 'http' => 200]);
});

it('rejects a Rain card webhook with a bad signature without changing state', function (): void {
    config(['cardissuance.issuers.rain.webhook_secret' => 's3cr3t']);
    $user = User::factory()->create();
    lifecycleCard((string) $user->id, 'rain-2', 'active', 'rain');
    $service = $this->app->make(CardWebhookService::class);

    $payload = ['event_id' => 'evt-2', 'type' => 'card.status', 'data' => ['card_id' => 'rain-2', 'status' => 'frozen']];
    $rawBody = (string) json_encode($payload);

    $result = $service->handle('rain', $rawBody, ['X-Rain-Signature' => 'bad']);
    expect($result)->toBe(['status' => 'rejected', 'http' => 401])
        ->and(Card::query()->where('issuer_card_token', 'rain-2')->value('status'))->toBe('active');
});

// ── FinCard generic adapter ──────────────────────────────────────────────

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::flush();

    $this->app->instance(FinCardClient::class, new FinCardClient(
        baseUrl: 'https://sandbox.finhub.cloud/api/v2.1/fincard/virtual',
        tenantId: 't',
        orgId: 'o',
        userId: 'u',
        username: 'x',
        password: 'test-password',
    ));

    Http::fake([
        'sandbox.finhub.cloud/api/v2.1/admin/*' => Http::response(['success' => true, 'data' => ['accessToken' => 'jwt', 'expiresIn' => 3600]]),
        'sandbox.finhub.cloud/api/v2.1/fincard/virtual/card/v2/openCard' => Http::response([
            'success' => true,
            'data' => ['cardId' => 'fc-1', 'cardNo' => '4111111111111111', 'network' => 'visa'],
        ]),
        'sandbox.finhub.cloud/api/v2.1/fincard/virtual/card/info' => Http::response([
            'success' => true,
            'data' => ['cardId' => 'fc-1', 'status' => 'normal', 'balance' => '50.00'],
        ]),
    ]);
});

it('opens a FinCard card through the generic adapter when metadata supplies the required inputs', function (): void {
    $adapter = $this->app->make(FinCardCardIssuerAdapter::class);

    $card = $adapter->createCard('1', 'Jane Smith', [
        'fincard_card_type_id' => 7,
        'amount_cents' => 5000,
        'fincard_holder_id' => 'h-1',
        'fincard_account_id' => 'acc-1',
        'idempotency_key' => 'ord-fc-1',
    ]);

    expect($card->cardToken)->toBe('fc-1')
        ->and($card->last4)->toBe('1111')
        ->and($card->pan)->toBeNull()
        ->and($card->cvv)->toBeNull();

    $mirror = Card::query()->where('issuer_card_token', 'fc-1')->firstOrFail();
    expect($mirror->status)->toBe('pending')
        ->and((int) $mirror->balance_cents)->toBe(5000)
        ->and($mirror->merchant_order_no)->toBe('ord-fc-1');
});

it('does not open a second FinCard card for a duplicate idempotency key', function (): void {
    $adapter = $this->app->make(FinCardCardIssuerAdapter::class);
    $meta = [
        'fincard_card_type_id' => 7,
        'amount_cents' => 5000,
        'fincard_holder_id' => 'h-1',
        'fincard_account_id' => 'acc-1',
        'idempotency_key' => 'ord-fc-2',
    ];

    $first = $adapter->createCard('1', 'Jane Smith', $meta);
    $second = $adapter->createCard('1', 'Jane Smith', $meta);

    expect($second->cardToken)->toBe($first->cardToken);
    $openCardCalls = collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'openCard'));
    expect($openCardCalls)->toHaveCount(1);
});

it('opens a FinCard card via the guided path for a known user and cardholder', function (): void {
    $user = User::factory()->create();

    $holder = new Cardholder();
    $holder->user_id = (string) $user->id;
    $holder->first_name = 'Jane';
    $holder->last_name = 'Smith';
    $holder->kyc_status = 'verified';
    $holder->issuer_cardholder_id = 'h-1';
    $holder->save();

    $account = new FinCardAccount();
    $account->user_id = $user->id;
    $account->fincard_account_id = 'acc-9';
    $account->currency = 'USD';
    $account->balance_cents = 100000;
    $account->status = 'active';
    $account->save();

    $card = $this->app->make(FinCardCardIssuerAdapter::class)->createCard((string) $user->id, 'Jane Smith', [
        'fincard_card_type_id' => 7,
        'amount_cents' => 2500,
        'cardholder_id' => (string) $holder->id,
        'idempotency_key' => 'ord-fc-3',
    ]);

    expect($card->cardToken)->toBe('fc-1');

    $mirror = Card::query()->where('issuer_card_token', 'fc-1')->firstOrFail();
    expect($mirror->fincard_account_id)->toBe('acc-9')
        ->and((string) $mirror->cardholder_id)->toBe((string) $holder->id);
});

it('throws an explicit unsupported error for FinCard createCard without the required inputs', function (): void {
    $this->app->make(FinCardCardIssuerAdapter::class)->createCard('1', 'Jane Smith');
})->throws(UnsupportedCardOperationException::class, 'fincard_card_type_id');

it('keeps FinCard wallet provisioning explicitly unsupported (no provider endpoint)', function (): void {
    $this->app->make(FinCardCardIssuerAdapter::class)
        ->getProvisioningData('fc-1', WalletType::APPLE_PAY, 'device-1');
})->throws(UnsupportedCardOperationException::class, 'no Apple/Google Pay push provisioning endpoint');

it('activates a FinCard card by syncing provider state', function (): void {
    $adapter = $this->app->make(FinCardCardIssuerAdapter::class);
    $adapter->createCard('1', 'Jane Smith', [
        'fincard_card_type_id' => 7,
        'amount_cents' => 5000,
        'fincard_holder_id' => 'h-1',
        'fincard_account_id' => 'acc-1',
        'idempotency_key' => 'ord-fc-4',
    ]);

    expect($adapter->activateCard('fc-1'))->toBeTrue()
        ->and(Card::query()->where('issuer_card_token', 'fc-1')->value('status'))->toBe('active');
});
