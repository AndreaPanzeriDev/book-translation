<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('chapter_position');
            $table->unsignedInteger('char_count');
            $table->string('source_hash', 64);
            $table->timestamps();

            $table->unique(['book_id', 'position']);
            $table->index(['book_id', 'chapter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
