<?php

declare(strict_types=1);

namespace App\Domain\Payment\Models;

use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Payment\Enums\PaymentIntentDirection;
use App\Domain\Payment\Enums\PaymentIntentKind;
use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Provider-independent payment intent (unified payment engine).
 *
 * One row per intent, whatever the provider (Stripe, HyperSwitch,
 * Paysera, bank rail, Iranian sandbox rail). Money is integer minor
 * units — never a float. State uses the canonical {@see TransactionState}
 * vocabulary. Refunds and reversals are separate intents linked via
 * `reverses_intent_id`; a terminal intent is never edited in place.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string|null $account_uuid
 * @property PaymentIntentDirection $direction
 * @property PaymentIntentKind $kind
 * @property string $provider
 * @property string|null $provider_reference
 * @property int $amount_minor
 * @property string $currency
 * @property TransactionState $state
 * @property string $idempotency_key
 * @property string|null $reverses_intent_id
 * @property string|null $failure_reason
 * @property \Illuminate\Support\Carbon|null $unknown_since_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property array<string, mixed>|null $metadata
 */
class PaymentIntent extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'provider_payment_intents';

    protected $fillable = [
        'user_id',
        'account_uuid',
        'direction',
        'kind',
        'provider',
        'provider_reference',
        'amount_minor',
        'currency',
        'state',
        'idempotency_key',
        'reverses_intent_id',
        'failure_reason',
        'unknown_since_at',
        'resolved_at',
        'metadata',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'direction'        => PaymentIntentDirection::class,
            'kind'             => PaymentIntentKind::class,
            'state'            => TransactionState::class,
            'amount_minor'     => 'integer',
            'metadata'         => 'array',
            'unknown_since_at' => 'datetime',
            'resolved_at'      => 'datetime',
        ];
    }

    /**
     * Normalized, provider-independent transaction shape. This is the
     * single representation every consumer (API, ledger posting,
     * reconciliation, admin) reads — never a provider-specific payload.
     *
     * @return array{
     *   intent_id: string, direction: string, kind: string, state: string,
     *   amount_minor: int, amount: string, currency: string,
     *   provider: string, provider_reference: string|null,
     *   reverses_intent_id: string|null, failure_reason: string|null,
     * }
     */
    public function normalized(): array
    {
        return [
            'intent_id'          => $this->id,
            'direction'          => $this->direction->value,
            'kind'               => $this->kind->value,
            'state'              => $this->state->value,
            'amount_minor'       => $this->amount_minor,
            'amount'             => self::minorToMajor($this->amount_minor, $this->currency),
            'currency'           => $this->currency,
            'provider'           => $this->provider,
            'provider_reference' => $this->provider_reference,
            'reverses_intent_id' => $this->reverses_intent_id,
            'failure_reason'     => $this->failure_reason,
        ];
    }

    /**
     * Integer minor units → exact major-unit decimal string. No float.
     */
    public static function minorToMajor(int $amountMinor, string $currency): string
    {
        $exponent = self::minorExponent($currency);

        return bcdiv((string) $amountMinor, bcpow('10', (string) $exponent), $exponent);
    }

    /**
     * Integer minor units ← exact major-unit decimal string. No float.
     *
     * @param numeric-string $amountMajor
     */
    public static function majorToMinor(string $amountMajor, string $currency): int
    {
        return (int) bcmul($amountMajor, bcpow('10', (string) self::minorExponent($currency)), 0);
    }

    public static function minorExponent(string $currency): int
    {
        return match (strtoupper($currency)) {
            'JPY', 'KRW', 'VND', 'CLP'                      => 0,
            'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' => 3,
            default                                         => 2,
        };
    }
}
