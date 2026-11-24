<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('otc_deals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('deal_id')->unique();
            $table->uuid('quote_id')->unique();
            $table->string('account_id');
            $table->string('side', 10); // buy|sell (from the requester's perspective)
            $table->string('base_currency', 10);
            $table->string('quote_currency', 10);
            $table->decimal('amount', 36, 18);
            $table->decimal('price', 36, 18);
            $table->decimal('value', 36, 18);
            $table->decimal('fee', 36, 18)->default(0);
            $table->string('status', 20)->default('settled'); // settled|reversed
            // Ledger journal entry numbers posted for this deal (audit trail).
            $table->json('ledger_entry_numbers')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otc_deals');
    }
};
