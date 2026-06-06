<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('word_id')->nullable()->constrained('words')->nullOnDelete();
            $table->string('word', 100);
            $table->string('source', 15)->default('manual'); // search / manual / quiz_weak / quiz_new / ai_review
            $table->text('notes')->nullable();
            $table->string('tags', 200)->nullable();
            $table->decimal('ease_factor', 4, 2)->default(2.5);
            $table->integer('interval_days')->default(0);
            $table->integer('repetitions')->default(0);
            $table->timestamp('next_review_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique('word');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_words');
    }
};
