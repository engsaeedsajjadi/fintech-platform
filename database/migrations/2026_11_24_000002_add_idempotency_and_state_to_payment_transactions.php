<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Canonical-state + idempotency columns for PaymentTransaction rows
        // created by PaymentTransferService. `status` keeps its legacy
        // values for old rows; new rows store the canonical vocabulary
        // (initiated/pending/processing/successful/failed/reversed/unknown/
        // requires-manual-review) in the same column.
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->string('idempotency_key')->nullable()->unique()->after('reference');
            $table->dateTime('unknown_since_at')->nullable()->after('failed_at');
            $table->string('reversed_by')->nullable()->after('unknown_since_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropColumn(['idempotency_key', 'unknown_since_at', 'reversed_by']);
        });
    }
};
