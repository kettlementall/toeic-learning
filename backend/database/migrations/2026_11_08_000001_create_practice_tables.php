<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The real-test practice bank: fixed questions from course material (Part 1–7
 * plus dictation), answered as many times as a learner likes.
 *
 * Deliberately separate from quizzes / quiz_questions / user_words /
 * review_logs. Those drive the vocabulary SM-2 schedule, the anti-repeat
 * cooldown (recentlyTestedPenalties looks at the last three quizzes of any
 * type) and the dashboard averages; a 46-question mock test written into them
 * would quietly bend all three. Nothing here reads or writes those tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A sittable unit: one mock test, or one review exercise from a lecture.
        Schema::create('practice_sets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();          // e.g. zhuifen-1, w4-part3-review
            $table->string('title', 200);
            $table->string('source', 20);                  // zhuifen | lecture
            $table->string('section', 20);                 // reading | listening | dictation
            $table->unsignedTinyInteger('week')->nullable();
            $table->unsignedSmallInteger('time_limit_seconds')->nullable(); // reading mock timer
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['section', 'sort']);
        });

        // Material shared by several questions: a passage, a conversation/talk
        // (audio + transcript), a chart, or a single Part 1 photo / Part 2 item.
        Schema::create('practice_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_id')->constrained('practice_sets')->cascadeOnDelete();
            $table->string('key', 60);                     // stable id inside the set file
            $table->string('label', 60)->nullable();       // e.g. 131–134
            $table->text('body')->nullable();              // passage / retyped chart (markdown-ish)
            $table->json('audio')->nullable();             // paths played in order, relative to the media root
            $table->text('transcript')->nullable();
            $table->string('image_path', 200)->nullable(); // Part 1 photos only
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['set_id', 'key']);
        });

        Schema::create('practice_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_id')->constrained('practice_sets')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('practice_groups')->nullOnDelete();
            $table->unsignedSmallInteger('number');        // the number printed on the paper (101, 147…)
            $table->unsignedTinyInteger('part');           // 1–7
            $table->text('stem')->nullable();              // Part 1/2 have none on the paper
            $table->json('options');                       // 3 (Part 2) or 4 strings, in source order — never shuffled
            $table->char('answer', 1);
            $table->string('answer_source', 20);           // verified | handwriting | supplement | solved | confirmed
            $table->string('skill', 30)->nullable();       // config('practice.skills') key
            $table->text('explanation')->nullable();       // from the supplement handout
            $table->text('ai_explanation')->nullable();    // generated on demand, then cached
            $table->string('ai_model', 60)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['set_id', 'number']);
        });

        Schema::create('practice_dictations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_id')->constrained('practice_sets')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('audio', 200);
            $table->text('transcript');
            $table->timestamps();

            $table->unique(['set_id', 'number']);
        });

        Schema::create('practice_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('set_id')->nullable()->constrained('practice_sets')->nullOnDelete(); // null for a retry mix
            $table->string('mode', 10);                    // mock | practice | retry
            $table->string('section', 20);
            $table->json('question_ids');                  // in presentation order
            $table->string('status', 15)->default('in_progress'); // in_progress | completed
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('total')->default(0);
            $table->unsignedSmallInteger('time_limit_seconds')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('practice_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('practice_attempts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('practice_questions')->cascadeOnDelete();
            $table->char('choice', 1)->nullable();         // null = left blank
            $table->boolean('is_correct');
            $table->timestamps();

            $table->index(['user_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_answers');
        Schema::dropIfExists('practice_attempts');
        Schema::dropIfExists('practice_dictations');
        Schema::dropIfExists('practice_questions');
        Schema::dropIfExists('practice_groups');
        Schema::dropIfExists('practice_sets');
    }
};
