<?php

namespace Tests\Feature;

use App\Models\PracticeQuestion;
use App\Models\PracticeSet;
use App\Models\User;
use App\Services\PracticeImporter;
use App\Services\PracticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PracticeFixtures;
use Tests\TestCase;

/**
 * Grading, wrong-answer retry and per-skill stats over the practice bank.
 */
class PracticeServiceTest extends TestCase
{
    use PracticeFixtures, RefreshDatabase;

    private PracticeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPracticeRoot();
        $importer = new PracticeImporter($this->practiceRoot);
        $importer->import($this->readingSet());
        $importer->import($this->listeningSet());
        $this->svc = app(PracticeService::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownPracticeRoot();
        parent::tearDown();
    }

    private function id(int $number): int
    {
        return PracticeQuestion::where('number', $number)->value('id');
    }

    public function test_grading_scores_blanks_as_wrong_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $set = PracticeSet::where('code', 'demo-reading')->first();
        $attempt = $this->svc->startAttempt($user->id, $set, 'mock');

        $this->assertSame([101, 102, 131, 132], PracticeQuestion::whereIn('id', $attempt->question_ids)->orderBy('sort')->pluck('number')->all());
        $this->assertSame(1080, $attempt->time_limit_seconds, 'reading mock carries the timer');

        $graded = $this->svc->grade($attempt, [$this->id(101) => 'a', $this->id(102) => 'B', $this->id(131) => 'Z']);
        $this->assertSame(1, $graded->score);
        $this->assertSame('completed', $graded->status);
        $this->assertSame(4, $graded->answers()->count());
        $this->assertNull($graded->answers()->where('question_id', $this->id(131))->value('choice'), 'invalid letter stored as blank');

        $this->svc->grade($graded, [$this->id(102) => 'A']);
        $this->assertSame(4, $graded->answers()->count(), 'regrading a completed attempt does nothing');
    }

    public function test_retry_takes_questions_whose_latest_answer_was_wrong(): void
    {
        $user = User::factory()->create();
        $set = PracticeSet::where('code', 'demo-reading')->first();

        $first = $this->svc->startAttempt($user->id, $set, 'practice');
        $this->svc->grade($first, [$this->id(101) => 'B', $this->id(102) => 'B', $this->id(131) => 'A', $this->id(132) => 'A']);
        $second = $this->svc->startAttempt($user->id, $set, 'practice');
        $this->svc->grade($second, [$this->id(101) => 'A', $this->id(102) => 'B', $this->id(131) => 'A', $this->id(132) => 'A']);

        $this->assertSame([$this->id(102)], $this->svc->wrongQuestionIds($user->id, 'reading'), '101 was fixed on the second try');
        $this->assertSame([], $this->svc->wrongQuestionIds($user->id, 'listening'));
        $this->assertNull($this->svc->startRetry($user->id, 'listening'));

        $retry = $this->svc->startRetry($user->id, 'reading');
        $this->assertSame('retry', $retry->mode);
        $this->assertSame([$this->id(102)], $retry->question_ids);

        $other = User::factory()->create();
        $this->assertSame([], $this->svc->wrongQuestionIds($other->id, 'reading'), 'per user');
    }

    public function test_stats_break_accuracy_down_by_part_and_skill(): void
    {
        $user = User::factory()->create();
        $set = PracticeSet::where('code', 'demo-reading')->first();
        $attempt = $this->svc->startAttempt($user->id, $set, 'practice');
        $this->svc->grade($attempt, [$this->id(101) => 'A', $this->id(102) => 'B', $this->id(131) => 'A', $this->id(132) => 'B']);

        $stats = $this->svc->stats($user->id);
        $this->assertSame(['total' => 4, 'correct' => 2, 'rate' => 50.0], $stats['sections']['reading']);
        $this->assertSame(50.0, $stats['parts'][5]['rate']);
        $this->assertSame('詞性', $stats['skills']['word_form']['label']);
        $this->assertSame(0.0, $stats['skills']['collocation']['rate']);
    }
}
