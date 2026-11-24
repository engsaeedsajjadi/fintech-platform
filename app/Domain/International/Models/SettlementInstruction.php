<?php

declare(strict_types=1);

namespace App\Domain\International\Models;

use App\Domain\International\Enums\ChargeBearer;
use App\Domain\International\Enums\InternationalRail;
use App\Domain\International\Enums\SettlementInstructionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Settlement instruction attached to a transfer.
 *
 * This records HOW the transfer should settle (rail, charge bearer,
 * value date, remittance information). It holds no endpoint, no
 * credential and no provider session: execution is performed only by
 * the rail gateway, which today refuses every dispatch. `executed`
 * therefore means "an operator recorded an external settlement
 * confirmation with an external reference", never "we sent it".
 *
 * @property string $id
 * @property string $international_transfer_id
 * @property InternationalRail $rail
 * @property ChargeBearer $charge_bearer
 * @property string|null $value_date
 * @property string|null $remittance_information
 * @property SettlementInstructionStatus $status
 * @property string|null $failure_reason
 * @property string|null $external_reference
 */
class SettlementInstruction extends Model
{
    use HasUuids;

    protected $table = 'settlement_instructions';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string|class-string> */
    protected $casts = [
        'rail'          => InternationalRail::class,
        'charge_bearer' => ChargeBearer::class,
        'status'        => SettlementInstructionStatus::class,
        'value_date'    => 'date',
    ];

    /**
     * @return BelongsTo<InternationalTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InternationalTransfer::class, 'international_transfer_id');
    }
}
