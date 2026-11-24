<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Contracts;

use App\Domain\SafeDeal\Models\SafeDealTrade;
use Illuminate\Support\Collection;

interface SafeDealTradeRepositoryInterface
{
    public function find(string $tradeId): ?SafeDealTrade;

    public function findOrFail(string $tradeId): SafeDealTrade;

    public function save(SafeDealTrade $trade): SafeDealTrade;

    /** @return Collection<int, SafeDealTrade> */
    public function findByParty(string $partyId): Collection;

    /** @return Collection<int, SafeDealTrade> */
    public function findByStatus(string $status): Collection;
}
