<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('trading_pairs', function (Blueprint $table): void {
            $table->id();
            $table->string('base_currency', 10);
            $table->string('quote_currency', 10);
            $table->boolean('is_active')->default(true);
            $table->boolean('halted')->default(false);
            $table->boolean('market_orders_enabled')->default(false);
            $table->decimal('min_order_size', 36, 18)->default('0.0001');
            $table->decimal('max_order_size', 36, 18)->default('1000000');
            $table->decimal('tick_size', 36, 18)->default('0.00000001');
            // Nullable per-pair fee overrides (decimal rates, e.g. 0.001 = 0.1%).
            // Null means "fall back to config('trading.fees')".
            $table->decimal('maker_fee_rate', 10, 8)->nullable();
            $table->decimal('taker_fee_rate', 10, 8)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency']);
            $table->index('is_active');
            $table->index('halted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_pairs');
    }
};
