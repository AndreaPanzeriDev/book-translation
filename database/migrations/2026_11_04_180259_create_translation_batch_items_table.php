<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_account_id')->nullable()->constrained('ai_accounts')->nullOnDelete();
            $table->string('model', 190);
            $table->string('slot_key', 1);
            $table->unsignedInteger('page_position');
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->foreignId('translation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['translation_batch_id', 'status']);
            $table->index(['translation_batch_id', 'id']);
            $table->index(['ai_account_id', 'status']);
            $table->unique(
                ['translation_batch_id', 'page_id', 'ai_account_id', 'model'],
                'translation_batch_item_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_batch_items');
    }
};
