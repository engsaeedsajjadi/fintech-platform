<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Bank-account ownership verification records.
|
| Stores ONLY the holder name, last 4 digits, and a SHA-256 hash of the
| full account identifier — never the full account number or IBAN.
| Status stays pending / requires_manual_review until a real provider or
| a human reviewer with bank evidence confirms ownership.
*/
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('kyc_bank_account_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->index();
            $table->string('account_holder_name');
            $table->string('account_last4', 4);
            $table->string('account_hash', 64)->index();
            $table->string('status')->default('pending'); // pending, requires_manual_review, verified, rejected
            $table->string('provider')->default('sandbox');
            $table->string('provider_reference')->nullable();
            $table->foreignId('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_bank_account_verifications');
    }
};
