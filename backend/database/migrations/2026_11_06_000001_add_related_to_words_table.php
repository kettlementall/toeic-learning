<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            // AI near-synonyms in the word's TOEIC sense, each with how it differs:
            // [{word, note}]. Not per learner — which of them a learner already
            // knows is decided when a card asks. An empty list means 'asked, none'.
            $table->json('related')->nullable()->after('study_note');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn('related');
        });
    }
};
