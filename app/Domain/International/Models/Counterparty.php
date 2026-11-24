<?php

declare(strict_types=1);

namespace App\Domain\International\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A counterparty (the person or business at the other end of an
 * international transfer), owned by one user.
 *
 * Stores identity data needed for screening only (name, type, country).
 * No government ID numbers are stored here.
 *
 * @property string $id
 * @property int $user_id
 * @property string $type
 * @property string $full_name
 * @property string $country
 * @property string $screening_status
 * @property \Illuminate\Support\Carbon|null $last_screened_at
 */
class Counterparty extends Model
{
    use HasUuids;

    protected $table = 'counterparties';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'last_screened_at' => 'datetime',
    ];

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_BUSINESS = 'business';

    /**
     * @return HasMany<Beneficiary, $this>
     */
    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class, 'counterparty_id');
    }
}
