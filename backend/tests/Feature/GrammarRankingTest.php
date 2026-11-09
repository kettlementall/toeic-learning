<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\ReviewLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's Part 5 ranking reports answers given, not the weakness
 * signals an AI review writes into review_logs.
 */
class GrammarRankingTest extends TestCase
{
    use RefreshDatabase;

    private function part5(User $user, string $point, array $answers, string $status = 'completed'): Quiz
    {
        $quiz = Quiz::create([
            'user_id' => $user->id, 'title' => 'P5', 'type' => 'part5_grammar', 'scope' => 'mixed',
            'status' => $status, 'score' => count(array_filter($answers)), 'total' => count($answers), 'completed_at' => now(),
        ]);
        foreach ($answers as $correct) {
            $quiz->questions()->create([
                'grammar_point' => $point, 'question' => 'Q ____', 'options' => ['a', 'b', 'c', 'd'],
                'correct_answer' => 'A', 'user_answer' => $correct ? 'A' : 'B', 'is_correct' => $correct,
            ]);
        }

        return $quiz;
    }

    public function test_ranking_counts_answers_and_ignores_ai_boosts(): void
    {
        $user = User::factory()->create();
        $quiz = $this->part5($user, 'collocation', [true, true, true, false]);
        $this->part5($user, 'preposition', [false, false, true]);
        $this->part5($user, 'pronoun', [false, false], 'pending');           // not submitted
        $this->part5(User::factory()->create(), 'comparison', [false]);      // someone else's

        // what boostGrammar() leaves behind after an AI review
        for ($i = 0; $i < 20; $i++) {
            ReviewLog::create(['user_id' => $user->id, 'quiz_id' => $quiz->id, 'grammar_point' => 'collocation', 'quality' => 1, 'reviewed_at' => now()]);
        }

        $stats = $this->actingAs($user)->get('/')->assertOk()->viewData('grammarStats');

        $this->assertSame(
            [['preposition', 3, 67], ['collocation', 4, 25]],
            $stats->map(fn ($g) => [$g['point'], (int) $g['total'], (int) $g['error_rate']])->all(),
        );
    }
}
