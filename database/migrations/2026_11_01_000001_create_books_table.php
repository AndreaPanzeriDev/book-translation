<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('language', 16)->nullable();
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('cover_path')->nullable();
            $table->unsignedSmallInteger('page_char_limit')->default(1500);
            $table->string('target_language', 16)->default('it');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
