<?php

namespace App\Domain\Exchange\Activities;

use App\Domain\Exchange\Models\TradingHold;
use App\Domain\Exchange\Services\TradingHoldService;
use Illuminate\Support\Facades\DB;
use Workflow\Activity;

/**
 * Release a funds hold created by LockAccountBalanceActivity.
 *
 * The lock id is the TradingHold UUID. The previous implementation read
 * lock state from cache and wrote to App\Models\AssetBalance, a class
 * that does not exist.
 */
class ReleaseAccountBalanceActivity extends Activity
{
    public function execute(string $orderId, string $lockId): object
    {
        return DB::transaction(
            function () use ($orderId, $lockId) {
                $hold = TradingHold::where('hold_id', $lockId)->lockForUpdate()->first();

                if (! $hold) {
                    return (object) [
                        'success' => false,
                        'message' => 'Lock not found',
                    ];
                }

                if ($hold->source_id !== $orderId) {
                    return (object) [
                        'success' => false,
                        'message' => 'Lock does not belong to this order',
                    ];
                }

                $amount = (string) $hold->amount;
                $currency = $hold->asset_code;

                app(TradingHoldService::class)->release($hold);

                return (object) [
                    'success'        => true,
                    'amountReleased' => $amount,
                    'currency'       => $currency,
                ];
            }
        );
    }
}
