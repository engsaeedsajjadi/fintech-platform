<?php

declare(strict_types=1);

namespace App\Domain\International\Policies;

use App\Domain\International\Models\InternationalTransfer;
use App\Models\User;

/**
 * Ownership policy for international transfers.
 *
 * Invoked directly by the controllers (the model lives in a domain
 * namespace, so Laravel's convention-based policy discovery does not
 * apply — there is deliberately no framework registration to touch).
 * Operators (admin / compliance roles) are granted by the route
 * middleware, not by this policy: this policy answers the ownership
 * question only.
 */
final class InternationalTransferPolicy
{
    public function view(User $user, InternationalTransfer $transfer): bool
    {
        return (int) $transfer->user_id === (int) $user->id;
    }

    public function manage(User $user, InternationalTransfer $transfer): bool
    {
        return (int) $transfer->user_id === (int) $user->id;
    }
}
