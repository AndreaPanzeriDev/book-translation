<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_account_id')->nullable()->constrained('ai_accounts')->nullOnDelete();
            $table->string('model');
            $table->string('target_language', 16);
            $table->string('source_hash', 64);
            $table->string('prompt_version', 32);
            $table->string('params_hash', 64);
            $table->string('key_hash', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->json('translated_blocks')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('provider_response_id')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'ai_account_id', 'model', 'target_language'], 'translations_slot_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
