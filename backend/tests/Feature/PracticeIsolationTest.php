<?php

namespace Tests\Feature;

use App\Models\PracticeAttempt;
use App\Models\PracticeSet;
use App\Models\Quiz;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use App\Services\PracticeImporter;
use App\Services\PracticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PracticeFixtures;
use Tests\TestCase;

/**
 * The practice bank must not move the vocabulary schedule or anything the quiz
 * builder and dashboard read. Sitting real tests, retrying them, and importing
 * sets leaves quizzes, quiz_questions, user_words (incl. SM-2 state) and
 * review_logs byte-for-byte as they were.
 */
class PracticeIsolationTest extends TestCase
{
    use PracticeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPracticeRoot();
    }

    protected function tearDown(): void
    {
        $this->tearDownPracticeRoot();
        parent::tearDown();
    }

    private function snapshot(): array
    {
        return [
            'quizzes' => DB::table('quizzes')->orderBy('id')->get()->toArray(),
            'quiz_questions' => DB::table('quiz_questions')->orderBy('id')->get()->toArray(),
            'user_words' => DB::table('user_words')->orderBy('id')->get()->toArray(),
            'review_logs' => DB::table('review_logs')->orderBy('id')->get()->toArray(),
            'words' => DB::table('words')->count(),
        ];
    }

    /** What the dashboard shows: quiz totals, averages, grammar weakness, score estimate. */
    private function dashboard(User $user): string
    {
        $view = $this->actingAs($user)->get('/')->assertOk();

        return json_encode(array_map(fn ($k) => $view->viewData($k), [
            'totalWords', 'dueCount', 'totalQuizzes', 'avgAccuracy', 'grammarStats', 'posStats', 'scoreEstimate',
        ]));
    }

    public function test_practice_leaves_vocabulary_and_quiz_state_untouched(): void
    {
        $user = User::factory()->create();
        $word = Word::create(['word' => 'lease', 'definition_en' => 'a rental contract']);
        UserWord::create([
            'user_id' => $user->id, 'word_id' => $word->id, 'word' => 'lease', 'source' => 'search',
            'ease_factor' => 2.36, 'interval_days' => 6, 'repetitions' => 2, 'next_review_at' => now()->addDays(3),
        ]);
        $quiz = Quiz::create(['user_id' => $user->id, 'title' => 'Vocab', 'type' => 'vocab_mc', 'status' => 'completed', 'score' => 1, 'total' => 1, 'completed_at' => now()]);
        $quiz->questions()->create(['word' => 'lease', 'question' => 'lease means?', 'options' => ['a', 'b', 'c', 'd'], 'correct_answer' => 'A', 'user_answer' => 'A', 'is_correct' => true]);
        ReviewLog::create(['user_id' => $user->id, 'quiz_id' => $quiz->id, 'grammar_point' => 'word_form', 'quality' => 5, 'reviewed_at' => now()]);

        $dashboardBefore = $this->dashboard($user);
        $before = $this->snapshot();

        $importer = new PracticeImporter($this->practiceRoot);
        $importer->import($this->readingSet());
        $importer->import($this->listeningSet());

        $svc = app(PracticeService::class);
        foreach (PracticeSet::all() as $set) {
            for ($i = 0; $i < 3; $i++) { // three sittings: more than the quiz builder's 3-quiz lookback
                $attempt = $svc->startAttempt($user->id, $set, 'mock');
                $svc->grade($attempt, collect($attempt->question_ids)->mapWithKeys(fn ($id) => [$id => 'B'])->all());
            }
        }
        // and once more through the HTTP routes, result page and retry included
        $set = PracticeSet::where('code', 'demo-reading')->first();
        $this->actingAs($user)->post("/practice/sets/{$set->id}/start", ['mode' => 'mock']);
        $http = PracticeAttempt::latest('id')->first();
        $this->actingAs($user)->post("/practice/attempts/{$http->id}/submit", ['answers' => []]);
        $this->actingAs($user)->get("/practice/attempts/{$http->id}/result")->assertOk();
        $this->actingAs($user)->get('/practice')->assertOk();

        $retry = $svc->startRetry($user->id, 'reading');
        $this->assertNotNull($retry);
        $svc->grade($retry, []);

        $this->assertEquals($before, $this->snapshot());
        $this->assertSame($dashboardBefore, $this->dashboard($user));
    }
}
