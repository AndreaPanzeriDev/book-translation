<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_parallel_requests')
                ->default(4)
                ->after('request_mode');
        });
    }

    public function down(): void
    {
        Schema::table('ai_accounts', function (Blueprint $table) {
            $table->dropColumn('max_parallel_requests');
        });
    }
};
