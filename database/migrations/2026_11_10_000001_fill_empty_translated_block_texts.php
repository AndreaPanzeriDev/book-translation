<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('translations')
            ->where('status', 'completed')
            ->whereNotNull('translated_blocks')
            ->chunkById(200, function ($translations): void {
                foreach ($translations as $translation) {
                    $blocks = json_decode($translation->translated_blocks, true);

                    if (! is_array($blocks)) {
                        continue;
                    }

                    $changed = false;

                    foreach ($blocks as $index => $block) {
                        if (
                            ! is_array($block)
                            || ! isset($block['block_id'], $block['text'])
                            || ! is_string($block['text'])
                            || trim($block['text']) !== ''
                        ) {
                            continue;
                        }

                        $source = DB::table('blocks')
                            ->where('id', $block['block_id'])
                            ->where('page_id', $translation->page_id)
                            ->value('text');

                        if (! is_string($source) || trim($source) === '') {
                            continue;
                        }

                        $blocks[$index]['text'] = $source;
                        $changed = true;
                    }

                    if ($changed) {
                        DB::table('translations')
                            ->where('id', $translation->id)
                            ->update([
                                'translated_blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        //
    }
};
