<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Client-supplied idempotency key: replaying the same key returns
            // the original order instead of executing a second order.
            $table->uuid('idempotency_key')->nullable()->unique()->after('order_id');
            // Optimistic-concurrency version, bumped by the projector on every
            // state change (placed/matched/cancelled). Writers lock the row
            // (SELECT ... FOR UPDATE) and re-check state inside the lock.
            $table->unsignedInteger('version')->default(0)->after('idempotency_key');
            // Execution venue: 'order_book' (this service) or 'demo'.
            $table->string('source', 20)->default('order_book')->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['idempotency_key', 'version', 'source']);
        });
    }
};
