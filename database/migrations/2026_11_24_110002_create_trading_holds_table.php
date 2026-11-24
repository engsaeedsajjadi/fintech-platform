<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('trading_holds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('hold_id')->unique();
            // Identifier of the owning account, in the same form the order book
            // uses (Account id as passed to the trading services).
            $table->string('account_id');
            // Resolved Account uuid, used to join account_balances.
            $table->uuid('account_uuid')->nullable();
            $table->string('asset_code', 10);
            // Major-unit decimal amount currently reserved by this hold.
            $table->decimal('amount', 36, 18);
            // What reserved the funds: 'order_book' (an Order) or 'otc' (an OtcQuote).
            $table->string('source_type', 20);
            $table->string('source_id');
            $table->string('status', 20)->default('active'); // active|released|consumed
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'asset_code']);
            $table->index(['account_id', 'asset_code', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_holds');
    }
};
