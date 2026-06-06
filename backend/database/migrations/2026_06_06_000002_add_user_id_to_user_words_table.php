<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_words', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained('users')->cascadeOnDelete();
            // SRS "due words" lookups filter by user + due date
            $table->index(['user_id', 'next_review_at']);
        });

        // NOTE: the (word) -> (user_id, word) unique-key swap happens in the
        // backfill migration, AFTER existing rows get a user_id, to avoid a
        // composite-key clash while user_id is still NULL.
    }

    public function down(): void
    {
        Schema::table('user_words', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'next_review_at']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
