<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('card_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('issuer', 50);
            $table->string('provider_event_id');
            $table->string('event_type', 100);
            $table->json('raw_payload')->nullable();
            $table->string('status', 30)->default('received');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['issuer', 'provider_event_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_webhook_events');
    }
};
