<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\ReviewLog;
use App\Models\User;
use App\Services\ClaudeService;
use App\Services\QuizBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The adaptive Part 5 selector weighs grammar points by the learner's recent
 * Part 5 answers only, and the generated questions are pitched at the target
 * score.
 */
class GrammarSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function part5(User $user, string $point, array $answers, $completedAt = null, string $type = 'part5_grammar'): Quiz
    {
        $quiz = Quiz::create([
            'user_id' => $user->id, 'title' => 'P5', 'type' => $type, 'scope' => 'mixed',
            'status' => 'completed', 'score' => count(array_filter($answers)), 'total' => count($answers),
            'completed_at' => $completedAt ?? now(),
        ]);
        foreach ($answers as $correct) {
            $quiz->questions()->create([
                'grammar_point' => $point, 'question' => 'Q ____', 'options' => ['a', 'b', 'c', 'd'],
                'correct_answer' => 'A', 'user_answer' => $correct ? 'A' : 'B', 'is_correct' => $correct,
            ]);
        }

        return $quiz;
    }

    public function test_weights_follow_recent_part5_answers_only(): void
    {
        $user = User::factory()->create();
        $this->part5($user, 'preposition', [false, false, false, true]);           // 75% wrong
        $collocation = $this->part5($user, 'collocation', [true, true, true, true]); // all right
        $this->part5($user, 'pronoun', [false, false], now()->subDays(120));       // outside the window
        $this->part5(User::factory()->create(), 'relative', [false]);             // someone else's

        // what AI reviews of vocabulary quizzes used to leave behind
        $vocab = Quiz::create(['user_id' => $user->id, 'title' => 'V', 'type' => 'vocab_mc', 'scope' => 'mixed',
            'status' => 'completed', 'score' => 1, 'total' => 1, 'completed_at' => now()]);
        foreach ([$vocab, $collocation] as $q) {
            for ($i = 0; $i < 30; $i++) {
                ReviewLog::create(['user_id' => $user->id, 'quiz_id' => $q->id, 'grammar_point' => 'collocation',
                    'quality' => 1, 'reviewed_at' => now()]);
            }
        }

        $w = app(QuizBuilderService::class)->grammarWeights($user->id);

        $this->assertEqualsWithDelta(1.7, $w['preposition'], 1e-9);  // 0.2 + 0.75 × 2
        $this->assertEqualsWithDelta(0.2, $w['collocation'], 1e-9);  // boosts ignored
        $this->assertSame(1.0, $w['pronoun']);                       // stale answers ignored
        $this->assertSame(1.0, $w['relative']);                      // another learner's
        $this->assertCount(count(QuizBuilderService::GRAMMAR_POINTS), $w);
    }

    public function test_ai_review_no_longer_writes_grammar_logs(): void
    {
        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'summary' => 's', 'weaknesses' => 'w', 'review_words' => [], 'review_grammar' => ['collocation', '介系詞'],
        ])]]], 200)]);
        $user = User::factory()->create();
        $quiz = $this->part5($user, 'preposition', [false]);

        $this->actingAs($user)->post(route('quiz.ai-review', $quiz))->assertRedirect(route('quiz.review', $quiz));

        $this->assertSame(['collocation', '介系詞'], $quiz->fresh()->ai_review['review_grammar']); // still shown
        $this->assertSame(0, ReviewLog::where('user_id', $user->id)->count());
    }

    public function test_part5_questions_are_pitched_at_the_target_score(): void
    {
        config(['services.anthropic.api_key' => 'test-key', 'quiz.part5.target_score' => 860]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '[]']]], 200)]);

        app(ClaudeService::class)->generateQuiz(['preposition', 'conjunction'], 'part5_grammar');

        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], '目標 860 分以上')
            && str_contains($r['messages'][0]['content'], 'preposition, conjunction'));
    }
}
