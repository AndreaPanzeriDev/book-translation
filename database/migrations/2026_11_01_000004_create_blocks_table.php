<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('type', 32)->default('p');
            $table->text('text');
            $table->unsignedInteger('char_count');
            $table->timestamps();

            $table->unique(['page_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
