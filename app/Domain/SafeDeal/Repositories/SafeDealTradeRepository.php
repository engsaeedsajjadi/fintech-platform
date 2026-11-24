<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Repositories;

use App\Domain\SafeDeal\Contracts\SafeDealTradeRepositoryInterface;
use App\Domain\SafeDeal\Models\SafeDealTrade;
use Illuminate\Support\Collection;

class SafeDealTradeRepository implements SafeDealTradeRepositoryInterface
{
    public function find(string $tradeId): ?SafeDealTrade
    {
        return SafeDealTrade::where('trade_id', $tradeId)->first();
    }

    public function findOrFail(string $tradeId): SafeDealTrade
    {
        return SafeDealTrade::where('trade_id', $tradeId)->firstOrFail();
    }

    public function save(SafeDealTrade $trade): SafeDealTrade
    {
        $trade->save();

        return $trade;
    }

    /** @return Collection<int, SafeDealTrade> */
    public function findByParty(string $partyId): Collection
    {
        return SafeDealTrade::where('buyer_id', $partyId)
            ->orWhere('seller_id', $partyId)
            ->orderByDesc('created_at')
            ->get();
    }

    /** @return Collection<int, SafeDealTrade> */
    public function findByStatus(string $status): Collection
    {
        return SafeDealTrade::where('status', $status)->orderByDesc('created_at')->get();
    }
}
