<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('external_bank_account_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_uuid');
            $table->string('bank_code', 50);
            $table->string('provider', 50);
            $table->string('account_type', 50);
            $table->string('currency', 3);
            $table->string('status', 30)->default('pending');
            $table->string('idempotency_key');
            $table->string('external_id')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_uuid', 'provider', 'idempotency_key']);
            $table->index(['status']);
            $table->index(['external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_bank_account_applications');
    }
};
