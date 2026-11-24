<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Durably stored inbound card-provider webhook, keyed by provider event id.
 * The raw payload is stored BEFORE signature verification / processing, so a
 * duplicate delivery is acknowledged without re-processing and a rejected
 * payload is still auditable. Never store PAN/CVV here — payloads are
 * provider status events, not sensitive-data reads.
 *
 * @property string $id
 * @property string $issuer
 * @property string $provider_event_id
 * @property string $event_type
 * @property array<string, mixed>|null $raw_payload
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $processed_at
 */
class CardWebhookEvent extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'card_webhook_events';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'issuer',
        'provider_event_id',
        'event_type',
        'raw_payload',
        'status',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload'  => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
