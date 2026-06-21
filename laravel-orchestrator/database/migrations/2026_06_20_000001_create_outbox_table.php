<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 128);
            $table->string('aggregate_type', 64)->default('ai_task');
            $table->string('aggregate_id', 64);
            $table->jsonb('payload');
            $table->jsonb('metadata')->nullable();
            $table->string('status', 32)->default('pending'); // pending, processing, dispatched, failed
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(10);
            $table->text('last_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'locked_until']);
            $table->index('event_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
