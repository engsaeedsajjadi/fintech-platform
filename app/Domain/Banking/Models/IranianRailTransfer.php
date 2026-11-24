<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sandbox-only Iranian rail transfer row.
 *
 * Stores ONLY the local state machine: our own reference, the rail, the
 * amount, and the canonical state. It stores NO bank account numbers, NO
 * IBANs, NO card numbers, and NO bank endpoint data — none exists, and
 * none is invented.
 *
 * @property string $id
 * @property string $rail
 * @property string $reference
 * @property string $idempotency_key
 * @property int $amount_minor
 * @property string $amount_decimal
 * @property string $currency
 * @property string $state
 * @property string|null $failure_reason
 *
 * @method static IranianRailTransfer create(array<string, mixed> $attributes = [])
 */
class IranianRailTransfer extends Model
{
    use HasUuids;

    protected $table = 'iranian_rail_transfers';

    /** @var list<string> */
    protected $guarded = [];
}
