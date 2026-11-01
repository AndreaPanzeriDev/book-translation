<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_account_id')->constrained('ai_accounts')->cascadeOnDelete();
            $table->string('model_id');
            $table->string('label')->nullable();
            $table->unsignedInteger('context_length')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_account_id', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
