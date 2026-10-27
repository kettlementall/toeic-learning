<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\UserWord;
use App\Services\ScoreEstimateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's Reading score estimate: what it counts, what it ignores, and
 * that more right answers never mean a lower score.
 */
class ScoreEstimateTest extends TestCase
{
    use RefreshDatabase;

    private function estimate(User $user): array
    {
        return app(ScoreEstimateService::class)->estimate($user->id);
    }

    /**
     * A completed quiz with one question per entry in $answers (true = right).
     * Vocab quizzes take a word per answer, from $words.
     */
    private function quiz(User $user, string $type, array $answers, array $words = [], $completedAt = null): Quiz
    {
        $quiz = Quiz::create([
            'user_id' => $user->id,
            'title' => $type,
            'type' => $type,
            'scope' => 'mixed',
            'status' => 'completed',
            'score' => count(array_filter($answers)),
            'total' => count($answers),
            'completed_at' => $completedAt ?? now(),
        ]);

        foreach (array_values($answers) as $i => $correct) {
            $quiz->questions()->create([
                'word' => $words[$i] ?? null,
                'grammar_point' => $type === 'part5_grammar' ? 'word_form' : null,
                'question' => 'Q ____',
                'options' => ['a', 'b', 'c', 'd'],
                'correct_answer' => 'A',
                'user_answer' => $correct ? 'A' : 'B',
                'is_correct' => $correct,
            ]);
        }

        return $quiz;
    }

    private function introduce(User $user, array $words, string $source = 'quiz_new'): void
    {
        foreach ($words as $word) {
            UserWord::create(['user_id' => $user->id, 'word' => $word, 'source' => $source, 'next_review_at' => today()]);
        }
    }

    /** A user with $p of every component right, at a sample big enough to count. */
    private function userAt(float $p): User
    {
        $user = User::factory()->create();
        $words = array_map(fn ($i) => "w{$i}", range(1, 50));
        $this->introduce($user, $words);

        $answers = fn (int $n) => array_map(fn ($i) => $i < round($n * $p), range(0, $n - 1));
        $this->quiz($user, 'part5_grammar', $answers(40));
        $this->quiz($user, 'news_reading', $answers(20));
        $this->quiz($user, 'vocab_mc', $answers(50), $words);

        return $user;
    }

    public function test_no_answers_means_no_estimate(): void
    {
        $result = $this->estimate(User::factory()->create());

        $this->assertSame('insufficient', $result['status']);
        $this->assertArrayNotHasKey('score', $result);
        $this->assertSame('news_reading', $result['hint']);
    }

    public function test_grammar_boost_logs_are_not_answers(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 100; $i++) {
            ReviewLog::create(['user_id' => $user->id, 'grammar_point' => 'collocation', 'quality' => 1, 'reviewed_at' => now()]);
        }

        $this->assertSame('insufficient', $this->estimate($user)['status']);
    }

    public function test_vocab_counts_only_the_first_question_on_quiz_introduced_words(): void
    {
        $user = User::factory()->create();
        $this->introduce($user, ['alpha', 'beta']);
        $this->introduce($user, ['gamma'], 'search');

        // first exposure: alpha wrong, beta right; gamma was looked up, not new
        $this->quiz($user, 'vocab_mc', [false, true, false], ['alpha', 'beta', 'gamma'], now()->subDays(2));
        // later re-asks of the same words do not count again
        $this->quiz($user, 'vocab_mc', [true, true], ['alpha', 'beta'], now()->subDay());

        $c = $this->estimate($user)['components'];

        $this->assertSame(2, $c['nv']);
        $this->assertEqualsWithDelta(0.5, $c['pv'], 0.001);
    }

    public function test_score_rises_with_accuracy_and_stays_in_range(): void
    {
        $scores = array_map(fn ($p) => $this->estimate($this->userAt($p)), [0.0, 0.5, 0.8, 1.0]);

        foreach ($scores as $r) {
            $this->assertSame('ok', $r['status']);
            $this->assertSame('high', $r['confidence']);
            $this->assertGreaterThanOrEqual(5, $r['low']);
            $this->assertLessThanOrEqual(495, $r['high']);
            $this->assertLessThanOrEqual($r['score'], $r['low']);
            $this->assertGreaterThanOrEqual($r['score'], $r['high']);
        }

        $values = array_column($scores, 'score');
        $sorted = $values;
        sort($sorted);
        $this->assertSame($sorted, $values);
        $this->assertLessThan(60, $values[0]);
        $this->assertGreaterThan(440, $values[3]);
    }

    public function test_without_reading_answers_confidence_is_low(): void
    {
        $user = User::factory()->create();
        $this->quiz($user, 'part5_grammar', array_fill(0, 40, true));

        $result = $this->estimate($user);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame('news_reading', $result['hint']);
    }

    public function test_only_the_users_own_recent_answers_count(): void
    {
        $other = $this->userAt(1.0);
        $user = User::factory()->create();
        $this->quiz($user, 'part5_grammar', array_fill(0, 40, true), [], now()->subDays(200));

        $this->assertSame('insufficient', $this->estimate($user)['status']);
        $this->assertSame('ok', $this->estimate($other)['status']);
    }

    public function test_dashboard_shows_the_estimate(): void
    {
        $user = $this->userAt(0.7);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Estimated TOEIC Reading Score')
            ->assertSee((string) $this->estimate($user)['score']);
    }
}
