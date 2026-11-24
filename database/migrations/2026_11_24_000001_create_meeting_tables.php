<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('host_uuid')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 32)->default('support');
            $table->string('status', 32)->default('scheduled');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('provider', 32)->default('sandbox');
            $table->string('room_name')->nullable();
            $table->string('join_url')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['host_uuid', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('meeting_id')->index();
            $table->uuid('user_uuid')->index();
            $table->string('role', 32)->default('participant');
            $table->string('status', 32)->default('invited');
            $table->dateTime('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'user_uuid']);
            $table->foreign('meeting_id')->references('id')->on('meetings')->cascadeOnDelete();
        });

        Schema::create('meeting_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('meeting_id')->index();
            $table->uuid('user_uuid')->index();
            $table->text('body');
            $table->timestamps();

            $table->foreign('meeting_id')->references('id')->on('meetings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_messages');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};
