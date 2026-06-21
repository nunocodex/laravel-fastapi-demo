<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('task_uuid')->unique();
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('prompt_template', 512)->nullable();
            $table->jsonb('result')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tasks');
    }
};
