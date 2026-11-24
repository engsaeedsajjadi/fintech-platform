<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Manual-review queue for KYC verifications.
|
| One row per review decision. Append-only: decisions are never edited in
| place; a later decision is a new row and the latest row wins. This plus
| the AuditLog entries written by KycManualReviewService is the immutable
| history of who approved / rejected a verification, when, and why.
| A sandbox / simulated verification result can only reach "approved"
| through a row in this table created by a human reviewer.
*/
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('kyc_manual_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('kyc_verification_id')->index();
            $table->foreignId('user_id')->index();
            $table->foreignId('reviewer_id')->nullable()->index();
            $table->string('decision')->default('pending'); // pending, approved, rejected
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['kyc_verification_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_manual_reviews');
    }
};
