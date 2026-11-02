<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            // AI study note for words a learner keeps failing: TOEIC collocations,
            // easily confused words, and a word-part breakdown —
            // {collocations[], confusables[{word, note}], parts}. Shared reference
            // data, generated once per word the first time a leech needs it.
            $table->json('study_note')->nullable()->after('mnemonic');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn('study_note');
        });
    }
};
