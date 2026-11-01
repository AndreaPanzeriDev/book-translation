<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('title');
            $table->string('source_href')->nullable();
            $table->timestamps();

            $table->index(['book_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};
