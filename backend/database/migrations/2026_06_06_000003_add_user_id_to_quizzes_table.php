<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained('users')->cascadeOnDelete();
            // dashboard filters by user + status + completion time
            $table->index(['user_id', 'status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'completed_at']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
