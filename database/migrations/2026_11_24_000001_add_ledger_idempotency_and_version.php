<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Idempotency store for LedgerService::postIdempotent(): a replayed
        // key returns the original journal entry instead of posting twice.
        Schema::create('ledger_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('domain')->default('ledger');
            $table->uuid('journal_entry_id')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index('domain');
        });

        // Optimistic-concurrency version for LedgerService::postGuarded().
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(0)->after('is_active');
        });

        // Audit column linking a journal entry back to its idempotency key.
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('idempotency_key')->nullable()->unique()->after('source_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('idempotency_key');
        });

        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->dropColumn('version');
        });

        Schema::dropIfExists('ledger_idempotency_keys');
    }
};
