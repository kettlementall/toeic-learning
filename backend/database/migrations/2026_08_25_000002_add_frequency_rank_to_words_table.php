<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            // position in the curated TOEIC high-frequency list; lower = more
            // commonly tested. Null for words that did not come from that list.
            $table->unsignedInteger('frequency_rank')->nullable()->after('level');
            $table->index(['source', 'frequency_rank'], 'words_source_rank_idx');
        });

        // 'seed' / 'api' no longer fit every provenance we track
        Schema::table('words', function (Blueprint $table) {
            $table->string('source', 20)->default('api')->change();
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropIndex('words_source_rank_idx');
            $table->dropColumn('frequency_rank');
            $table->string('source', 10)->default('api')->change();
        });
    }
};
