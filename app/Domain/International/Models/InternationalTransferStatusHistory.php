<?php

declare(strict_types=1);

namespace App\Domain\International\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per status transition of a transfer — the transaction
 * history / status tracking trail. Rows are append-only: never updated
 * or deleted.
 *
 * @property int $id
 * @property string $international_transfer_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $actor_user_id
 * @property string|null $reason
 */
class InternationalTransferStatusHistory extends Model
{
    protected $table = 'international_transfer_status_history';

    /** @var list<string> */
    protected $guarded = [];
}
