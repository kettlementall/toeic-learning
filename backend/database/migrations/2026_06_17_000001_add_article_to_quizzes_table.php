<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            // News-reading quizzes store their source article + AI difficulty
            // assessment here: {source, topic, title, url, published_at,
            // passage, level, suitable, suitability_note, summary, vocab[]}.
            $table->json('article')->nullable()->after('ai_review');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('article');
        });
    }
};
