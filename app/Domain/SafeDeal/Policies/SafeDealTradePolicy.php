<?php

declare(strict_types=1);

namespace App\Domain\SafeDeal\Policies;

use App\Domain\SafeDeal\Models\SafeDealTrade;
use App\Models\User;

class SafeDealTradePolicy
{
    public function view(User $user, SafeDealTrade $trade): bool
    {
        return $trade->isParty((string) $user->id) || $user->hasRole('admin');
    }

    public function act(User $user, SafeDealTrade $trade): bool
    {
        return $trade->isParty((string) $user->id);
    }

    public function resolveDispute(User $user, SafeDealTrade $trade): bool
    {
        // Dispute resolution is an operator/admin act, never a party act.
        return $user->hasRole('admin');
    }

    public function reviewDocuments(User $user, SafeDealTrade $trade): bool
    {
        return $trade->isParty((string) $user->id) || $user->hasRole('admin');
    }

    public function assessRisk(User $user, SafeDealTrade $trade): bool
    {
        // A high-risk assessment cancels the trade, so it is a party or
        // admin act — never a stranger's.
        return $trade->isParty((string) $user->id) || $user->hasRole('admin');
    }
}
