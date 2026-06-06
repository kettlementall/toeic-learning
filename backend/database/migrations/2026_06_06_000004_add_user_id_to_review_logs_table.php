<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_logs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained('users')->cascadeOnDelete();
            // dashboard / adaptive stats group per user
            $table->index(['user_id', 'grammar_point']);
            $table->index(['user_id', 'part_of_speech']);
        });
    }

    public function down(): void
    {
        Schema::table('review_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'grammar_point']);
            $table->dropIndex(['user_id', 'part_of_speech']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
