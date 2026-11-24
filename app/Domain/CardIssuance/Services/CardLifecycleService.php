<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Services;

use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\Enums\CardNetwork;
use App\Domain\CardIssuance\Enums\CardStatus;
use App\Domain\CardIssuance\Events\CardActivated;
use App\Domain\CardIssuance\Events\CardBlocked;
use App\Domain\CardIssuance\Events\CardCancelled;
use App\Domain\CardIssuance\Events\CardIssuanceFailed;
use App\Domain\CardIssuance\Events\CardReplaced;
use App\Domain\CardIssuance\Events\CardRequested;
use App\Domain\CardIssuance\Events\CardStatusSynced;
use App\Domain\CardIssuance\Events\CardUnblocked;
use App\Domain\CardIssuance\Exceptions\UnsupportedCardOperationException;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Models\CardFundingAccount;
use App\Domain\CardIssuance\Models\CardIssuanceAttempt;
use App\Domain\CardIssuance\Models\Cardholder;
use App\Domain\CardIssuance\ValueObjects\VirtualCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Provider-neutral card lifecycle orchestration.
 *
 * Every mutation goes through the configured {@see CardIssuerInterface}
 * adapter; this service owns the durable local mirror (`cards` table),
 * idempotency (one card per idempotency key), state-transition validation
 * (via {@see CardStatus::canTransitionTo()}), and the failure path: a
 * provider failure is recorded on a {@see CardIssuanceAttempt} in
 * `requires_manual_review` and the exception propagates — a failed issuance
 * is never recorded as an issued card.
 *
 * PAN/CVV are ephemeral: {@see VirtualCard} may carry them transiently from
 * the provider, but this service never persists or logs them — only the
 * last-4 and the provider token reach the database.
 */
class CardLifecycleService
{
    public function __construct(
        private readonly CardIssuerInterface $issuer,
    ) {
    }

    /**
     * Request a card (virtual, or physical where the provider supports it —
     * see the issuer's capability flags).
     *
     * @param  array<string, mixed>  $metadata  Provider-specific inputs
     *                                           (e.g. FinCard card-type id
     *                                           + amount_cents).
     */
    public function requestCard(
        string $userId,
        string $cardholderName,
        array $metadata = [],
        ?CardNetwork $network = null,
        ?string $label = null,
        ?string $idempotencyKey = null,
    ): VirtualCard {
        $idempotencyKey ??= (string) Str::uuid();
        $issuerName = $this->issuer->getName();

        $existing = CardIssuanceAttempt::query()
            ->where('user_id', $userId)
            ->where('issuer', $issuerName)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof CardIssuanceAttempt && $existing->status === CardIssuanceAttempt::STATUS_SUCCEEDED) {
            $card = $this->issuer->getCard((string) $existing->card_token);
            if ($card !== null) {
                return $card;
            }
        }

        $attempt = $existing ?? new CardIssuanceAttempt();
        $attempt->user_id = $userId;
        $attempt->issuer = $issuerName;
        $attempt->idempotency_key = $idempotencyKey;
        $attempt->status = CardIssuanceAttempt::STATUS_PENDING;
        $attempt->error = null;
        $attempt->metadata = $this->sanitizeMetadata(array_merge($metadata, [
            'cardholder_name' => $cardholderName,
            'network'         => $network?->value,
            'label'           => $label,
        ]));
        $attempt->save();

        try {
            $card = $this->issuer->createCard(
                $userId,
                $cardholderName,
                array_merge($metadata, ['idempotency_key' => $idempotencyKey]),
                $network,
                $label,
            );
        } catch (Throwable $e) {
            $attempt->status = CardIssuanceAttempt::STATUS_REQUIRES_MANUAL_REVIEW;
            $attempt->error = $e->getMessage();
            $attempt->save();

            Event::dispatch(new CardIssuanceFailed($userId, $issuerName, $idempotencyKey, $e->getMessage()));

            throw $e;
        }

        $this->persistMirror($card, $userId, $idempotencyKey, $this->resolveCardholder($userId, $cardholderName));

        $attempt->status = CardIssuanceAttempt::STATUS_SUCCEEDED;
        $attempt->card_token = $card->cardToken;
        $attempt->provider_reference = $card->cardToken;
        $attempt->save();

        Event::dispatch(new CardRequested($userId, $card->cardToken, $issuerName, $idempotencyKey));

        return $card;
    }

    /**
     * Activate a pending card. Returns null while the provider still reports
     * the card as pending (not an error — retry later or wait for the
     * provider webhook). Throws when the issuer has no activation call.
     */
    public function activateCard(string $cardToken): ?VirtualCard
    {
        $mirror = $this->mirrorOrFail($cardToken);

        if ($mirror->status === CardStatus::ACTIVE->value) {
            return $this->issuer->getCard($cardToken);
        }
        if (! CardStatus::tryFrom($mirror->status)?->canTransitionTo(CardStatus::ACTIVE)) {
            throw new RuntimeException("Card {$cardToken} in status {$mirror->status} cannot be activated.");
        }
        if (! $this->issuer->getCapabilities()->supportsActivation) {
            throw new UnsupportedCardOperationException(
                "Issuer {$this->issuer->getName()} has no activation call — the card activates via provider webhook; use syncStatus() to pick up the provider state.",
            );
        }

        if (! $this->issuer->activateCard($cardToken)) {
            return null;
        }

        $mirror->status = CardStatus::ACTIVE->value;
        $mirror->save();

        Event::dispatch(new CardActivated((string) $mirror->user_id, $cardToken));

        return $this->issuer->getCard($cardToken);
    }

    /**
     * Temporarily block (freeze) an active card.
     */
    public function blockCard(string $cardToken, ?string $reason = null): VirtualCard
    {
        $mirror = $this->mirrorOrFail($cardToken);
        $this->assertTransition($mirror, CardStatus::FROZEN, 'block');

        if (! $this->issuer->freezeCard($cardToken)) {
            throw new RuntimeException("Provider refused to freeze card {$cardToken}.");
        }

        $mirror->status = CardStatus::FROZEN->value;
        $mirror->frozen_at = now();
        $mirror->save();

        Event::dispatch(new CardBlocked((string) $mirror->user_id, $cardToken, $reason));

        $card = $this->issuer->getCard($cardToken);

        return $card ?? $this->toVirtualCard($mirror);
    }

    /**
     * Unblock (unfreeze) a frozen card.
     */
    public function unblockCard(string $cardToken): VirtualCard
    {
        $mirror = $this->mirrorOrFail($cardToken);
        $this->assertTransition($mirror, CardStatus::ACTIVE, 'unblock');

        if (! $this->issuer->unfreezeCard($cardToken)) {
            throw new RuntimeException("Provider refused to unfreeze card {$cardToken}.");
        }

        $mirror->status = CardStatus::ACTIVE->value;
        $mirror->frozen_at = null;
        $mirror->save();

        Event::dispatch(new CardUnblocked((string) $mirror->user_id, $cardToken));

        $card = $this->issuer->getCard($cardToken);

        return $card ?? $this->toVirtualCard($mirror);
    }

    /**
     * Permanently cancel a card.
     */
    public function cancelCard(string $cardToken, string $reason): VirtualCard
    {
        $mirror = $this->mirrorOrFail($cardToken);
        $this->assertTransition($mirror, CardStatus::CANCELLED, 'cancel');

        if (! $this->issuer->cancelCard($cardToken, $reason)) {
            throw new RuntimeException("Provider refused to cancel card {$cardToken}.");
        }

        $mirror->status = CardStatus::CANCELLED->value;
        $mirror->cancelled_at = now();
        $mirror->save();

        Event::dispatch(new CardCancelled((string) $mirror->user_id, $cardToken, $reason));

        $card = $this->issuer->getCard($cardToken);

        return $card ?? $this->toVirtualCard($mirror);
    }

    /**
     * Replace a card: cancel the old one and issue a new one. The new card
     * is linked to the old one via metadata; the old card is never deleted.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function replaceCard(
        string $cardToken,
        string $reason,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): VirtualCard {
        $old = $this->mirrorOrFail($cardToken);
        $this->assertTransition($old, CardStatus::CANCELLED, 'replace');

        $this->cancelCard($cardToken, $reason);

        $userId = (string) $old->user_id;
        $new = $this->requestCard(
            $userId,
            $old->cardholder?->getFullName() ?? '',
            array_merge($metadata, ['replaces_card_token' => $cardToken, 'replacement_reason' => $reason]),
            CardNetwork::tryFrom($old->network),
            $old->label,
            $idempotencyKey,
        );

        Event::dispatch(new CardReplaced($userId, $cardToken, $new->cardToken, $reason));

        return $new;
    }

    /**
     * Pull the provider's current state for a card into the local mirror.
     */
    public function syncStatus(string $cardToken): VirtualCard
    {
        $mirror = $this->mirrorOrFail($cardToken);
        $card = $this->issuer->getCard($cardToken);
        if ($card === null) {
            throw new InvalidArgumentException("Provider has no card {$cardToken}.");
        }

        $mirror->status = $card->status->value;
        $mirror->last4 = $card->last4;
        $mirror->save();

        Event::dispatch(new CardStatusSynced((string) $mirror->user_id, $cardToken, $card->status->value));

        return $card;
    }

    /**
     * Delivery / fulfilment tracking for a physical card. No wired issuer
     * supports it today (see capability flags), so this always fails with an
     * explicit not-supported error rather than returning invented tracking.
     *
     * @return array<string, mixed>
     */
    public function getDeliveryStatus(string $cardToken): array
    {
        $this->mirrorOrFail($cardToken);

        if (! $this->issuer->getCapabilities()->supportsDeliveryTracking) {
            throw new UnsupportedCardOperationException(
                "Issuer {$this->issuer->getName()} does not support delivery tracking for card {$cardToken}.",
            );
        }

        throw new UnsupportedCardOperationException(
            "Issuer {$this->issuer->getName()} advertises delivery tracking but no tracking endpoint is implemented.",
        );
    }

    /**
     * Link a card to its provider funding account (the balance its loads
     * come from). The funding account must exist and belong to the card's
     * owner.
     */
    public function linkToAccount(string $cardToken, string $fundingAccountId): Card
    {
        $mirror = $this->mirrorOrFail($cardToken);

        $funding = CardFundingAccount::query()->find($fundingAccountId);
        if (! $funding instanceof CardFundingAccount) {
            throw new InvalidArgumentException("Funding account {$fundingAccountId} not found.");
        }
        if ((string) $funding->user_id !== (string) $mirror->user_id) {
            throw new InvalidArgumentException('Funding account does not belong to the card owner.');
        }

        $mirror->funding_account_id = $funding->id;
        $mirror->save();

        return $mirror;
    }

    // ── Admin hooks ──────────────────────────────────────────────────────

    /**
     * @return EloquentCollection<int, CardIssuanceAttempt>
     */
    public function listAttempts(?string $status = null, int $limit = 50): EloquentCollection
    {
        $query = CardIssuanceAttempt::query()->orderByDesc('created_at');
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Retry a failed issuance with the SAME idempotency key, so a provider
     * that already issued the card does not issue a second one.
     */
    public function retryIssuance(string $attemptId): VirtualCard
    {
        $attempt = CardIssuanceAttempt::query()->find($attemptId);
        if (! $attempt instanceof CardIssuanceAttempt) {
            throw new InvalidArgumentException("Issuance attempt {$attemptId} not found.");
        }
        if ($attempt->status === CardIssuanceAttempt::STATUS_SUCCEEDED) {
            throw new RuntimeException('Issuance attempt already succeeded.');
        }

        $meta = is_array($attempt->metadata) ? $attempt->metadata : [];
        $cardholderName = (string) ($meta['cardholder_name'] ?? '');
        $network = isset($meta['network']) ? CardNetwork::tryFrom((string) $meta['network']) : null;
        $label = isset($meta['label']) ? (string) $meta['label'] : null;
        $providerMeta = array_diff_key($meta, array_flip(['cardholder_name', 'network', 'label']));

        try {
            $card = $this->issuer->createCard(
                (string) $attempt->user_id,
                $cardholderName,
                array_merge($providerMeta, ['idempotency_key' => $attempt->idempotency_key]),
                $network,
                $label,
            );
        } catch (Throwable $e) {
            $attempt->status = CardIssuanceAttempt::STATUS_REQUIRES_MANUAL_REVIEW;
            $attempt->error = $e->getMessage();
            $attempt->save();

            Event::dispatch(new CardIssuanceFailed(
                (string) $attempt->user_id,
                (string) $attempt->issuer,
                (string) $attempt->idempotency_key,
                $e->getMessage(),
            ));

            throw $e;
        }

        $this->persistMirror(
            $card,
            (string) $attempt->user_id,
            (string) $attempt->idempotency_key,
            $this->resolveCardholder((string) $attempt->user_id, $cardholderName),
        );

        $attempt->status = CardIssuanceAttempt::STATUS_SUCCEEDED;
        $attempt->card_token = $card->cardToken;
        $attempt->provider_reference = $card->cardToken;
        $attempt->error = null;
        $attempt->save();

        Event::dispatch(new CardRequested(
            (string) $attempt->user_id,
            $card->cardToken,
            (string) $attempt->issuer,
            (string) $attempt->idempotency_key,
        ));

        return $card;
    }

    /**
     * Resolve a manual-review attempt: 'retry' re-issues with the same
     * idempotency key, 'reject' closes it as failed.
     */
    public function resolveManualReview(string $attemptId, string $resolution, ?string $note = null): CardIssuanceAttempt
    {
        $attempt = CardIssuanceAttempt::query()->find($attemptId);
        if (! $attempt instanceof CardIssuanceAttempt) {
            throw new InvalidArgumentException("Issuance attempt {$attemptId} not found.");
        }

        if ($resolution === 'retry') {
            $this->retryIssuance($attemptId);

            return $attempt->fresh() ?? $attempt;
        }

        if ($resolution === 'reject') {
            $attempt->status = CardIssuanceAttempt::STATUS_FAILED;
            $attempt->error = $note ?? $attempt->error;
            $attempt->save();

            return $attempt;
        }

        throw new InvalidArgumentException("Unknown manual-review resolution: {$resolution} (expected 'retry' or 'reject').");
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function mirrorOrFail(string $cardToken): Card
    {
        $card = Card::query()
            ->where('issuer_card_token', $cardToken)
            ->where('issuer', $this->issuer->getName())
            ->first();

        if (! $card instanceof Card) {
            throw new InvalidArgumentException("Card {$cardToken} not found for issuer {$this->issuer->getName()}.");
        }

        return $card;
    }

    private function assertTransition(Card $mirror, CardStatus $target, string $action): void
    {
        $current = CardStatus::tryFrom($mirror->status);
        if ($current === null || ! $current->canTransitionTo($target)) {
            throw new RuntimeException("Cannot {$action} card {$mirror->issuer_card_token} in status {$mirror->status}.");
        }
    }

    /**
     * Persist (or refresh) the local mirror of a provider card. PAN/CVV are
     * deliberately never copied — only the token, last-4 and status. The
     * cards table requires a cardholder, so the user's local cardholder is
     * resolved (or created from the request name) for the mirror row.
     */
    private function persistMirror(VirtualCard $card, string $userId, string $idempotencyKey, Cardholder $cardholder): Card
    {
        /** @var Card $mirror */
        $mirror = Card::query()->updateOrCreate(
            ['issuer_card_token' => $card->cardToken, 'issuer' => $this->issuer->getName()],
            [
                'user_id'           => $userId,
                'cardholder_id'     => $cardholder->id,
                'last4'             => $card->last4,
                'network'           => $card->network->value,
                'status'            => $card->status->value,
                'currency'          => 'USD',
                'label'             => $card->label,
                'merchant_order_no' => $idempotencyKey,
                'expires_at'        => $card->expiresAt,
            ],
        );

        return $mirror;
    }

    /**
     * Resolve the user's local cardholder for a mirror row, creating a
     * minimal one from the request name when the user has none yet. This
     * creates only a local record — it is not a KYC verdict and not a
     * provider cardholder.
     */
    private function resolveCardholder(string $userId, string $cardholderName): Cardholder
    {
        $existing = Cardholder::query()->where('user_id', $userId)->orderBy('id')->first();
        if ($existing instanceof Cardholder) {
            return $existing;
        }

        // The mirror's cardholder row references the platform user, so the
        // user must exist — otherwise there is no one to issue the card to.
        if (User::query()->find($userId) === null) {
            throw new InvalidArgumentException("Unknown user: {$userId}.");
        }

        [$first, $last] = $this->splitName($cardholderName);

        $holder = new Cardholder();
        $holder->user_id = (string) $userId;
        $holder->first_name = $first;
        $holder->last_name = $last;
        $holder->kyc_status = 'pending';
        $holder->save();

        return $holder;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $cardholderName): array
    {
        $parts = explode(' ', trim($cardholderName), 2);

        return [$parts[0] !== '' ? $parts[0] : 'Cardholder', $parts[1] ?? ''];
    }

    private function toVirtualCard(Card $mirror): VirtualCard
    {
        return new VirtualCard(
            cardToken: $mirror->issuer_card_token,
            last4: $mirror->last4,
            network: CardNetwork::tryFrom($mirror->network) ?? CardNetwork::VISA,
            status: CardStatus::tryFrom($mirror->status) ?? CardStatus::PENDING,
            cardholderName: $mirror->cardholder?->getFullName() ?? '',
            expiresAt: $mirror->expires_at !== null
                ? \DateTimeImmutable::createFromInterface($mirror->expires_at)
                : new \DateTimeImmutable('+3 years'),
            label: $mirror->label,
        );
    }

    /**
     * Strip anything sensitive from metadata before it is stored on an
     * attempt row (PAN/CVV must never reach the database, even inside a
     * metadata blob).
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        unset($metadata['pan'], $metadata['cvv'], $metadata['cvv2'], $metadata['pin']);

        return $metadata;
    }
}
