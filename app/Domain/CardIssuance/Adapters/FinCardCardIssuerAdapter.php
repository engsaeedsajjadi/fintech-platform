<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Adapters;

use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\Enums\CardNetwork;
use App\Domain\CardIssuance\Enums\CardStatus;
use App\Domain\CardIssuance\Enums\WalletType;
use App\Domain\CardIssuance\Exceptions\UnsupportedCardOperationException;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Models\Cardholder;
use App\Domain\CardIssuance\Services\FinCardCardService;
use App\Domain\CardIssuance\ValueObjects\CardIssuerCapabilities;
use App\Domain\CardIssuance\ValueObjects\CardTransaction;
use App\Domain\CardIssuance\ValueObjects\ProvisioningData;
use App\Domain\CardIssuance\ValueObjects\VirtualCard;
use App\Infrastructure\FinCard\FinCardClient;
use App\Models\User;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Str;

/**
 * FinCard adapter for the generic CardIssuerInterface (GraphQL + any
 * CardIssuerInterface consumer).
 *
 * `createCard` is implemented against {@see FinCardClient::openCard()} — but
 * FinCard's prefunded model needs more than the generic signature carries
 * (card-type id, initial load in minor units, a verified cardholder and a
 * funded account), so those arrive via `$metadata`:
 * `fincard_card_type_id` (or config `default_card_type_id`),
 * `amount_cents`, plus either a local `cardholder_id` for a known user or
 * provider-native `fincard_holder_id` + `fincard_account_id`. When any
 * required input is missing the call throws
 * {@see UnsupportedCardOperationException} with the missing inputs named —
 * it never invents a card. The guided flow remains POST
 * `/v1/cards/fincard/open`. `getProvisioningData` still throws: FinCard v1
 * has no Apple/Google Pay push-provisioning endpoint — `FinCardClient`
 * exposes no provisioning call at all, verified by inspection.
 *
 * Reads come from the local Card mirror; state changes delegate to
 * {@see FinCardCardService}.
 *
 * @see docs/superpowers/specs/2026-07-06-fincard-card-issuing-design.md §3.2
 */
final class FinCardCardIssuerAdapter implements CardIssuerInterface
{
    public function __construct(
        private readonly FinCardCardService $cardService,
        private readonly FinCardClient $client,
    ) {
    }

    public function getCapabilities(): CardIssuerCapabilities
    {
        // FinCard is a prefunded virtual-card programme: no physical cards,
        // no wallet push provisioning in v1, no delivery tracking. Top-up
        // (account → card) exists via FinCardCardService::topUp. Activation
        // is not a provider call — cards become active via the provider
        // webhook, so activateCard() only syncs state (see below).
        return new CardIssuerCapabilities(
            supportsPhysicalCards: false,
            supportsWalletProvisioning: false,
            supportsDeliveryTracking: false,
            supportsTopUp: true,
            supportsSpendLimits: false,
            supportsActivation: false,
        );
    }

    /**
     * Activate by syncing state from FinCard: there is no provider activate
     * endpoint, so this refreshes the local mirror from the provider and
     * reports whether the card is active yet. Returns false (not an
     * exception) while the card is still pending — the caller retries later
     * or waits for the provider webhook.
     */
    public function activateCard(string $cardToken): bool
    {
        $card = $this->findCard($cardToken);
        if (! $card instanceof Card) {
            return false;
        }

        $refreshed = $this->cardService->refresh($card);

        return $refreshed->status === 'active';
    }

    /**
     * Open a FinCard virtual card via the provider.
     *
     * @param  array<string, mixed>  $metadata  fincard_card_type_id,
     *                                           amount_cents,
     *                                           cardholder_id (local) or
     *                                           fincard_holder_id +
     *                                           fincard_account_id,
     *                                           idempotency_key /
     *                                           merchant_order_no (optional)
     *
     * @throws UnsupportedCardOperationException when a required FinCard
     *                                           input is missing.
     */
    public function createCard(
        string $userId,
        string $cardholderName,
        array $metadata = [],
        ?CardNetwork $network = null,
        ?string $label = null,
    ): VirtualCard {
        // Idempotency: a caller-supplied key that already opened a card
        // returns the same card instead of opening a second one.
        $idempotencyKey = $metadata['idempotency_key'] ?? $metadata['merchant_order_no'] ?? null;
        $orderNo = is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : $this->orderNo();
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = Card::query()
                ->where('user_id', $userId)
                ->where('issuer', 'fincard')
                ->where('merchant_order_no', $orderNo)
                ->first();
            if ($existing instanceof Card) {
                return $this->toVirtualCard($existing);
            }
        }

        $cardTypeId = (int) ($metadata['fincard_card_type_id']
            ?? config('cardissuance.issuers.fincard.default_card_type_id')
            ?? 0);
        $amountCents = (int) ($metadata['amount_cents'] ?? 0);
        if ($cardTypeId <= 0 || $amountCents <= 0) {
            throw new UnsupportedCardOperationException(
                'FinCard createCard requires metadata fincard_card_type_id and amount_cents (minor units) — use POST /v1/cards/fincard/open for the guided flow.',
            );
        }

        // Guided path: known platform user + verified local cardholder. The
        // service resolves the funded account and persists the card mirror.
        $user = User::query()->find($userId);
        $cardholder = isset($metadata['cardholder_id'])
            ? Cardholder::query()->find($metadata['cardholder_id'])
            : null;
        if ($user instanceof User && $cardholder instanceof Cardholder) {
            $card = $this->cardService->openCard($user, $cardholder, $cardTypeId, $amountCents, $orderNo, []);
            if ($label !== null) {
                $card->label = $label;
                $card->save();
            }

            return $this->toVirtualCard($card);
        }

        // Direct path: caller supplies provider-native ids.
        $holderId = (string) ($metadata['fincard_holder_id'] ?? '');
        $accountId = (string) ($metadata['fincard_account_id'] ?? '');
        if ($holderId === '' || $accountId === '') {
            throw new UnsupportedCardOperationException(
                'FinCard createCard requires either a local cardholder_id for a known user, or metadata fincard_holder_id + fincard_account_id.',
            );
        }

        $response = $this->client->openCard(
            $cardTypeId,
            bcdiv((string) $amountCents, '100', 2),
            $accountId,
            $holderId,
            $orderNo,
            [],
        );
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        // Persist the local mirror (same shape as FinCardCardService::openCard).
        // PAN/CVV are never present here — sensitive details are fetched
        // ephemerally via getCardSensitive() and never stored. The mirror
        // needs a local cardholder row (cards.cardholder_id is NOT NULL).
        $localCardholder = $this->ensureLocalCardholder($userId, $cardholderName, $holderId);

        $card = new Card();
        $card->user_id = $userId;
        $card->cardholder_id = $localCardholder->id;
        $card->issuer_card_token = (string) ($data['cardId'] ?? $data['id'] ?? '');
        $card->issuer = 'fincard';
        $card->last4 = $this->extractLast4($data);
        $card->network = $network !== null ? $network->value : $this->mapNetwork((string) ($data['network'] ?? $data['cardBrand'] ?? 'visa'));
        $card->status = 'pending';
        $card->currency = 'USD';
        $card->balance_cents = $amountCents;
        $card->fincard_account_id = $accountId;
        $card->merchant_order_no = $orderNo;
        $card->label = $label;
        $card->save();

        return $this->toVirtualCard($card);
    }

    /**
     * @param  array<string>  $certificates
     */
    public function getProvisioningData(
        string $cardToken,
        WalletType $walletType,
        string $deviceId,
        array $certificates = [],
    ): ProvisioningData {
        // Verified by inspection: FinCardClient exposes no provisioning
        // endpoint (no push-provisioning call exists in the v1 BFF API), so
        // this cannot be implemented against the provider — explicit
        // unsupported, never a fabricated pass.
        throw new UnsupportedCardOperationException('FinCard v1 has no Apple/Google Pay push provisioning endpoint.');
    }

    public function freezeCard(string $cardToken): bool
    {
        $card = $this->findCard($cardToken);
        if (! $card instanceof Card) {
            return false;
        }
        $this->cardService->freeze($card, $this->orderNo());

        return true;
    }

    public function unfreezeCard(string $cardToken): bool
    {
        $card = $this->findCard($cardToken);
        if (! $card instanceof Card) {
            return false;
        }
        $this->cardService->unfreeze($card, $this->orderNo());

        return true;
    }

    public function cancelCard(string $cardToken, string $reason): bool
    {
        $card = $this->findCard($cardToken);
        if (! $card instanceof Card) {
            return false;
        }
        $this->cardService->cancel($card, $this->orderNo());

        return true;
    }

    public function getCard(string $cardToken): ?VirtualCard
    {
        $card = $this->findCard($cardToken);

        return $card instanceof Card ? $this->toVirtualCard($card) : null;
    }

    /**
     * @return array<VirtualCard>
     */
    public function listUserCards(string $userId): array
    {
        return Card::query()
            ->where('user_id', $userId)
            ->where('issuer', 'fincard')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Card $card): VirtualCard => $this->toVirtualCard($card))
            ->all();
    }

    /**
     * @return array{transactions: array<CardTransaction>, next_cursor: string|null}
     */
    public function getTransactions(string $cardToken, int $limit = 20, ?string $cursor = null): array
    {
        $page = $cursor !== null && ctype_digit($cursor) ? max((int) $cursor, 1) : 1;
        $response = $this->client->getCardPurchaseTransactions($cardToken, $page, $limit);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $records = is_array($data['records'] ?? null) ? $data['records'] : (array_is_list($data) ? $data : []);

        $transactions = [];
        foreach ($records as $record) {
            if (is_array($record)) {
                $transactions[] = $this->toCardTransaction($cardToken, $record);
            }
        }

        $hasMore = count($records) >= $limit;

        return ['transactions' => $transactions, 'next_cursor' => $hasMore ? (string) ($page + 1) : null];
    }

    public function getName(): string
    {
        return 'fincard';
    }

    private function findCard(string $cardToken): ?Card
    {
        return Card::query()
            ->where('issuer_card_token', $cardToken)
            ->where('issuer', 'fincard')
            ->first();
    }

    private function toVirtualCard(Card $card): VirtualCard
    {
        return new VirtualCard(
            cardToken: $card->issuer_card_token,
            last4: $card->last4,
            network: CardNetwork::tryFrom($card->network) ?? CardNetwork::VISA,
            status: CardStatus::tryFrom($card->status) ?? CardStatus::PENDING,
            cardholderName: $card->cardholder?->getFullName() ?? '',
            expiresAt: $card->expires_at !== null
                ? DateTimeImmutable::createFromInterface($card->expires_at)
                : new DateTimeImmutable('+3 years'),
            metadata: [],
            label: $card->label,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function toCardTransaction(string $cardToken, array $record): CardTransaction
    {
        $amount = (string) ($record['amount'] ?? $record['tradeAmount'] ?? '0');
        $amountCents = is_numeric($amount) ? (int) bcmul(bcadd($amount, '0', 2), '100', 0) : 0;
        $ts = (string) ($record['tradeTime'] ?? $record['createTime'] ?? '');

        return new CardTransaction(
            transactionId: (string) ($record['orderNo'] ?? $record['tradeNo'] ?? $record['id'] ?? ''),
            cardToken: $cardToken,
            merchantName: (string) ($record['merchantName'] ?? $record['merchant'] ?? ''),
            merchantCategory: (string) ($record['merchantCategory'] ?? $record['mcc'] ?? ''),
            amountCents: $amountCents,
            currency: (string) ($record['currency'] ?? 'USD'),
            status: (string) ($record['status'] ?? 'settled'),
            timestamp: $this->parseTimestamp($ts),
        );
    }

    private function parseTimestamp(string $ts): DateTimeImmutable
    {
        if ($ts !== '' && ctype_digit($ts)) {
            // Epoch seconds or milliseconds.
            $seconds = strlen($ts) > 11 ? (int) substr($ts, 0, 10) : (int) $ts;

            return (new DateTimeImmutable())->setTimestamp($seconds);
        }

        try {
            return $ts !== '' ? new DateTimeImmutable($ts) : new DateTimeImmutable();
        } catch (Exception) {
            return new DateTimeImmutable();
        }
    }

    private function orderNo(): string
    {
        return 'ADP' . str_replace('-', '', (string) Str::uuid());
    }

    /**
     * Resolve the local cardholder mirror for a user, creating a minimal
     * one from the request name when the user has none yet. Local record
     * only — not a KYC verdict and not a provider cardholder.
     */
    private function ensureLocalCardholder(string $userId, string $cardholderName, ?string $issuerCardholderId = null): Cardholder
    {
        $existing = Cardholder::query()->where('user_id', $userId)->orderBy('id')->first();
        if ($existing instanceof Cardholder) {
            return $existing;
        }

        if (User::query()->find($userId) === null) {
            throw new UnsupportedCardOperationException("FinCard createCard: unknown user {$userId}.");
        }

        $parts = explode(' ', trim($cardholderName), 2);
        $holder = new Cardholder();
        $holder->user_id = (string) $userId;
        $holder->first_name = $parts[0] !== '' ? $parts[0] : 'Cardholder';
        $holder->last_name = $parts[1] ?? '';
        $holder->kyc_status = 'pending';
        $holder->issuer_cardholder_id = $issuerCardholderId;
        $holder->save();

        return $holder;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractLast4(array $data): string
    {
        foreach (['last4', 'lastFour', 'cardLast4'] as $key) {
            $v = (string) ($data[$key] ?? '');
            if ($v !== '') {
                return substr($v, -4);
            }
        }
        $pan = (string) ($data['cardNo'] ?? $data['maskedPan'] ?? '');

        return $pan !== '' ? substr($pan, -4) : '0000';
    }

    private function mapNetwork(string $network): string
    {
        return match (strtolower($network)) {
            'mastercard', 'master' => 'mastercard',
            'discover'             => 'discover',
            default                => 'visa',
        };
    }
}
