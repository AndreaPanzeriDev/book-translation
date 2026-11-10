<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translation_batch_items', function (Blueprint $table) {
            $table->timestamp('dispatched_at')
                ->nullable()
                ->after('finished_at');

            $table->index(
                ['ai_account_id', 'dispatched_at', 'finished_at'],
                'translation_batch_items_dispatch_index',
            );
        });

        // Gli item pendenti o in esecuzione prima di questa migrazione erano
        // già stati accodati: contarli evita di superare il limite al deploy.
        DB::table('translation_batch_items')
            ->whereIn('status', ['pending', 'running'])
            ->update(['dispatched_at' => DB::raw('COALESCE(started_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('translation_batch_items', function (Blueprint $table) {
            $table->dropIndex('translation_batch_items_dispatch_index');
            $table->dropColumn('dispatched_at');
        });
    }
};
