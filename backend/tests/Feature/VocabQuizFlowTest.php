<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use App\Services\SpacedRepetitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The vocabulary quiz over HTTP, end to end: build, answer, submit. The rest of
 * the suite drives the services directly, so this is what covers the wiring —
 * that creating a quiz repairs the review schedule, that its questions come off
 * the due queue, and that submitting grades the cards the way quizCorrect() and
 * demote() intend.
 */
class VocabQuizFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.anthropic.api_key' => 'test-key',
            'srs.new_word_share' => 0.2,
        ]);
    }

    /**
     * Answers the generator the way the real one does: one question per word it
     * was asked about, so the quiz that comes back reflects the selection.
     */
    private function fakeClaude(array $knownWords): void
    {
        Http::fake(['api.anthropic.com/*' => function ($request) use ($knownWords) {
            $body = $request->body();

            $asked = array_values(array_filter(
                $knownWords,
                fn ($w) => str_contains($body, '"'.$w.'"') || str_contains($body, $w.',') || str_contains($body, $w.'\n')
            ));

            $questions = array_map(fn ($word) => [
                'word' => $word,
                'question' => "Which sentence uses \"{$word}\" correctly?",
                'options' => ["A sentence with {$word}.", 'Wrong one.', 'Another wrong one.', 'A third wrong one.'],
                'correct_answer' => 'A',
                'explanation' => "Because {$word} means what it means.",
            ], $asked);

            return Http::response(['content' => [['text' => json_encode($questions)]]], 200);
        }]);
    }

    /** @return string[] every word the fake generator is allowed to echo back */
    private function seedLibrary(User $user): array
    {
        $words = [];

        // a pile of overdue cards for the quiz to draw its review portion from
        for ($i = 0; $i < 20; $i++) {
            $words[] = "word{$i}";
            UserWord::create([
                'user_id' => $user->id,
                'word' => "word{$i}",
                'source' => 'quiz_new',
                'ease_factor' => 2.5,
                'interval_days' => 10,
                'repetitions' => 4,
                'next_review_at' => now()->subDays(6),
                'last_reviewed_at' => now()->subDays(16),
            ]);
        }

        // and a curated list for the new-word share to draw from
        for ($i = 0; $i < 10; $i++) {
            $words[] = "core{$i}";
            Word::create([
                'word' => "core{$i}",
                'source' => Word::SOURCE_TOEIC_CORE,
                'frequency_rank' => $i + 1,
                'definition_zh' => '意思',
            ]);
        }

        return $words;
    }

    public function test_building_a_quiz_draws_from_the_due_queue(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $words = $this->seedLibrary($user);
        $this->fakeClaude($words);
        $srs = app(SpacedRepetitionService::class);

        $this->assertSame(20, $srs->backlogCount($user->id), '20 overdue before the quiz');

        $response = $this->post(route('quiz.store'), [
            'type' => 'vocab_mc',
            'mode' => 'smart',
            'count' => 10,
        ]);

        $quiz = Quiz::where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect(route('quiz.show', $quiz));
        $this->assertSame('pending', $quiz->status);
        $this->assertSame(10, $quiz->questions()->count());

        // Building a quiz reschedules nothing — grading does. All 20 are
        // still ripe (none has been answered yet), plus the 2 new words the
        // quiz added to the library, which join the queue due now.
        $this->assertSame(22, $srs->backlogCount($user->id));

        // 8 review words off the due queue, 2 new ones from the curated list
        $asked = $quiz->questions()->pluck('word');
        $fromLibrary = $asked->filter(fn ($w) => str_starts_with($w, 'word'))->count();
        $this->assertSame(8, $fromLibrary, 'the review portion is the daily capacity');
        $this->assertSame(2, $asked->count() - $fromLibrary, 'and the rest is new material');

        // every new word joins the library, due today
        $this->assertTrue(
            UserWord::forUser($user->id)->where('source', 'quiz_new')
                ->whereIn('word', $asked->filter(fn ($w) => str_starts_with($w, 'core')))
                ->get()
                ->every(fn ($uw) => $uw->next_review_at->isToday()),
            'new words enter the queue due today'
        );
    }

    public function test_submitting_grades_a_hit_as_good_and_puts_a_miss_back_on_today(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $words = $this->seedLibrary($user);
        $this->fakeClaude($words);

        $this->post(route('quiz.store'), ['type' => 'vocab_mc', 'mode' => 'smart', 'count' => 10]);
        $quiz = Quiz::where('user_id', $user->id)->firstOrFail();

        // answer every question but the last correctly
        $questions = $quiz->questions()->get();
        $missed = $questions->last();
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q->id] = $q->is($missed)
                ? collect(['A', 'B', 'C', 'D'])->first(fn ($l) => $l !== $q->correct_answer)
                : $q->correct_answer;
        }

        $hit = $questions->first(fn ($q) => str_starts_with($q->word, 'word') && ! $q->is($missed));

        $this->post(route('quiz.submit', $quiz), ['answers' => $answers])
            ->assertRedirect(route('quiz.review', $quiz));

        $quiz->refresh();
        $this->assertSame('completed', $quiz->status);
        $this->assertSame($questions->count() - 1, $quiz->score);
        $this->assertNotNull($quiz->completed_at);

        // a hit on a due card is worth a "good": the interval grows, ease does not
        $hitCard = UserWord::forUser($user->id)->where('word', $hit->word)->firstOrFail();
        $this->assertSame(2.5, (float) $hitCard->ease_factor, 'recognition does not raise ease');
        $this->assertGreaterThan(10, $hitCard->interval_days, 'the interval still grows');
        $this->assertTrue($hitCard->next_review_at->isFuture());
        $this->assertSame(
            1,
            ReviewLog::where('user_word_id', $hitCard->id)
                ->where('quality', SpacedRepetitionService::QUIZ_CORRECT_QUALITY)
                ->count()
        );

        // a miss goes back on today's queue with one lapse against it
        $missedCard = UserWord::forUser($user->id)->where('word', $missed->word)->firstOrFail();
        $this->assertTrue($missedCard->next_review_at->isToday(), 'a miss comes back today');
        $this->assertSame(1, $missedCard->lapses);
        $this->assertSame(1, ReviewLog::where('user_word_id', $missedCard->id)->where('quality', 1)->count());
    }

    public function test_the_next_quiz_in_the_sitting_asks_about_other_words(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $words = $this->seedLibrary($user);
        $this->fakeClaude($words);

        $this->post(route('quiz.store'), ['type' => 'vocab_mc', 'mode' => 'smart', 'count' => 10]);
        $first = Quiz::where('user_id', $user->id)->firstOrFail();

        // built before the first is answered: its words are still "due"
        $this->post(route('quiz.store'), ['type' => 'vocab_mc', 'mode' => 'smart', 'count' => 10]);
        $second = Quiz::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertTrue($second->isNot($first));
        $this->assertEmpty(
            array_intersect(
                $first->questions()->pluck('word')->all(),
                $second->questions()->pluck('word')->all()
            ),
            'a second quiz in the same sitting does not repeat the open one'
        );
    }
}
