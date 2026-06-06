<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 100)->unique();
            $table->string('phonetic', 100)->nullable();
            $table->string('audio_url', 255)->nullable();
            $table->string('part_of_speech', 20)->nullable();
            $table->text('definition_en')->nullable();
            $table->text('definition_zh')->nullable();
            $table->text('example')->nullable();
            $table->text('toeic_note')->nullable();
            $table->string('synonyms', 255)->nullable();
            $table->unsignedTinyInteger('level')->default(3);
            $table->string('category', 50)->nullable();
            $table->string('source', 10)->default('api'); // seed / api
            $table->json('raw_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('words');
    }
};
