<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('translation_batches', function (Blueprint $table) {
            $table->string('locale', 8)->default('it')->after('target_language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('translation_batches', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
