<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_words', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('last_reviewed_at');
            $table->index(['user_id', 'next_review_at'], 'user_words_user_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_words', function (Blueprint $table) {
            $table->dropIndex('user_words_user_due_idx');
            $table->dropColumn('suspended_at');
        });
    }
};
