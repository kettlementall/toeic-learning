<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('type', 20);   // vocab_mc / part5_grammar / fill_blank
            $table->string('scope', 10)->default('mixed'); // builtin / custom / mixed
            $table->string('status', 20)->default('pending'); // pending / completed
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedTinyInteger('total')->default(0);
            $table->json('ai_review')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
