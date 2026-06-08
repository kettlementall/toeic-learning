<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Models\UserWord;
use App\Services\QuizBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeakWordSelectionTest extends TestCase
{
    use RefreshDatabase;

    /** Create a weak, overdue user word with a fixed ease factor. */
    private function weakWord(User $user, string $word, float $ease = 2.0): void
    {
        UserWord::create([
            'user_id' => $user->id,
            'word' => $word,
            'source' => 'manual',
            'ease_factor' => $ease,
            'next_review_at' => now()->subDay(), // overdue
        ]);
    }

    /** Record a completed quiz that tested the given words. */
    private function pastQuiz(User $user, array $words): Quiz
    {
        $quiz = Quiz::create([
            'user_id' => $user->id,
            'title' => 'past',
            'type' => 'vocab_mc',
            'scope' => 'mixed',
            'status' => 'completed',
            'total' => count($words),
        ]);
        foreach ($words as $w) {
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'word' => $w,
                'question' => "Q $w",
                'options' => ['a', 'b', 'c', 'd'],
                'correct_answer' => 'A',
            ]);
        }

        return $quiz;
    }

    public function test_cooldown_never_starves_selection(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $words = ['alpha', 'bravo', 'charlie', 'delta', 'echo'];
        foreach ($words as $w) {
            $this->weakWord($user, $w);
        }
        // every word was just tested -> all penalized, but none excluded
        $this->pastQuiz($user, $words);

        $picked = app(QuizBuilderService::class)->selectWords(5, 'weak');

        $this->assertCount(5, $picked);
        $this->assertEqualsCanonicalizing($words, $picked);
    }

    public function test_recently_tested_words_are_picked_less_often(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // two equally-weak, equally-overdue words; only difference is cooldown
        $this->weakWord($user, 'recent');
        $this->weakWord($user, 'fresh');

        // 'recent' was in the latest quiz (strongest penalty); 'fresh' never tested
        $this->pastQuiz($user, ['recent']);

        $builder = app(QuizBuilderService::class);
        $counts = ['recent' => 0, 'fresh' => 0];
        for ($i = 0; $i < 400; $i++) {
            $pick = $builder->selectWords(1, 'weak');
            $counts[$pick[0]]++;
        }

        // expected ratio ~6.7x (0.15 vs 1.0); assert a safe margin to avoid flakiness
        $this->assertGreaterThan(
            $counts['recent'] * 2,
            $counts['fresh'],
            "fresh ({$counts['fresh']}) should beat recent ({$counts['recent']}) clearly"
        );
    }
}
