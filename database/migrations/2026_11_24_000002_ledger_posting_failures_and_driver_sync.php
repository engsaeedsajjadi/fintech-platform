<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Durable failure tracking for PostGlEntryListener (Phase 4.2-D):
        // a failed auto-posting is recorded here AND rethrown, so the
        // business operation is never silently marked successful while its
        // journal entry is missing. Operators reconcile/retry from this table.
        Schema::create('ledger_posting_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_name');
            $table->string('event_id')->nullable();
            $table->string('source_domain')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->text('event_payload')->nullable();
            $table->text('error');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('event_name');
            $table->index('idempotency_key');
        });

        // Transactional outbox for external driver sync (Phase 4.2-E): the
        // database journal is the source of truth; a failed TigerBeetle
        // sync is recorded here as pending instead of failing the business
        // operation or being forgotten. `ledger:retry-driver-sync` replays it.
        Schema::create('ledger_driver_syncs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('journal_entry_id')->unique();
            $table->string('driver');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // Request fingerprint for LedgerService::postIdempotent(): a replay
        // with a different payload under the same key is a conflict, not a
        // silent return of the original entry.
        Schema::table('ledger_idempotency_keys', function (Blueprint $table): void {
            $table->string('request_hash')->nullable()->after('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_idempotency_keys', function (Blueprint $table): void {
            $table->dropColumn('request_hash');
        });

        Schema::dropIfExists('ledger_driver_syncs');
        Schema::dropIfExists('ledger_posting_failures');
    }
};
