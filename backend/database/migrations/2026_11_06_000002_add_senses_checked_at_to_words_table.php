<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            // When the meanings were last chosen for their TOEIC sense by the AI.
            // Null for entries imported as the dictionary's first definition per
            // part of speech, which words:fix-meanings repairs.
            $table->timestamp('senses_checked_at')->nullable()->after('meanings');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn('senses_checked_at');
        });
    }
};
