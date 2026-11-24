<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Sandbox-only Iranian rail transfers (SandboxIranianRailAdapter).
        // This table stores ONLY the local state machine: our own reference,
        // the rail, the amount, and the canonical state. It stores NO bank
        // account numbers, NO IBANs, NO card numbers, and NO bank endpoint
        // data — none exists, and none is invented. Production execution is
        // disabled until official CBI/bank documentation and credentials
        // exist (see config/payment_rails.php and the adapter).
        Schema::create('iranian_rail_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('rail');
            $table->string('reference')->unique();
            $table->string('idempotency_key')->unique();
            $table->bigInteger('amount_minor');
            $table->string('amount_decimal');
            $table->string('currency', 3)->default('IRR');
            $table->string('state');
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['rail', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iranian_rail_transfers');
    }
};
