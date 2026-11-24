<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Models;

use App\Domain\SafeDeal\Enums\RiskLevel;
use App\Domain\SafeDeal\Enums\TradeStatus;
use App\Domain\SafeDeal\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * SafeDeal trade (the projection / read model of the trade aggregate).
 *
 * Money is stored as decimal(20,4) strings; never floats. The authoritative
 * history is the append-only safe_deal_events table plus the terminal
 * safe_deal_audit_records row — this row is the current-state projection.
 */
class SafeDealTrade extends Model
{
    protected $table = 'safe_deal_trades';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string|class-string> */
    protected $casts = [
        'status'              => TradeStatus::class,
        'buyer_verification'  => VerificationStatus::class,
        'seller_verification' => VerificationStatus::class,
        'risk_level'          => RiskLevel::class,
        'terms'               => 'array',
        'metadata'            => 'array',
        'risk_factors'        => 'array',
        'buyer_approved_at'   => 'datetime',
        'seller_approved_at'  => 'datetime',
        'funded_at'           => 'datetime',
        'delivered_at'        => 'datetime',
        'settled_at'          => 'datetime',
        'cancelled_at'        => 'datetime',
        'refunded_at'         => 'datetime',
    ];

    /** @return HasMany<SafeDealMilestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(SafeDealMilestone::class, 'trade_id');
    }

    /** @return HasMany<SafeDealDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(SafeDealDocument::class, 'trade_id');
    }

    /** @return HasMany<SafeDealDispute, $this> */
    public function disputes(): HasMany
    {
        return $this->hasMany(SafeDealDispute::class, 'trade_id');
    }

    /** @return HasMany<SafeDealEventRecord, $this> */
    public function eventRecords(): HasMany
    {
        return $this->hasMany(SafeDealEventRecord::class, 'trade_id');
    }

    /** @return HasOne<SafeDealAuditRecord, $this> */
    public function auditRecord(): HasOne
    {
        return $this->hasOne(SafeDealAuditRecord::class, 'trade_id');
    }

    public function isBuyer(string $partyId): bool
    {
        return $this->buyer_id === $partyId;
    }

    public function isSeller(string $partyId): bool
    {
        return $this->seller_id === $partyId;
    }

    public function isParty(string $partyId): bool
    {
        return $this->isBuyer($partyId) || $this->isSeller($partyId);
    }

    public function bothPartiesVerified(): bool
    {
        return $this->buyer_verification === VerificationStatus::VERIFIED
            && $this->seller_verification === VerificationStatus::VERIFIED;
    }

    public function bothApproved(): bool
    {
        return $this->buyer_approved_at !== null && $this->seller_approved_at !== null;
    }

    public function allMilestonesCompleted(): bool
    {
        return $this->milestones()->count() > 0
            && $this->milestones()->where('status', '!=', 'completed')->count() === 0;
    }

    public function milestoneTotal(): string
    {
        return (string) $this->milestones()->sum('amount');
    }
}
