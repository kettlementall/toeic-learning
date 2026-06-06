<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_word_id')->nullable()->constrained('user_words')->nullOnDelete();
            $table->foreignId('quiz_id')->nullable()->constrained('quizzes')->nullOnDelete();
            $table->string('category', 50)->nullable();
            $table->string('part_of_speech', 20)->nullable();
            $table->string('grammar_point', 30)->nullable();
            $table->unsignedTinyInteger('quality')->default(0); // 0-5
            $table->timestamp('reviewed_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_logs');
    }
};
