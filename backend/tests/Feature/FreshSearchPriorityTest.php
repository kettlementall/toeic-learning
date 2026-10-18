<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use App\Services\QuizBuilderService;
use App\Services\SpacedRepetitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreshSearchPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => null]);
    }

    /** A word just looked up: in the library, never tested. */
    private function searched(User $user, string $word, array $attrs = []): UserWord
    {
        return UserWord::create(array_merge([
            'user_id' => $user->id,
            'word' => $word,
            'source' => 'search',
            'next_review_at' => today(),
        ], $attrs));
    }

    /** An established card that has been through a few reviews. */
    private function card(User $user, string $word, array $attrs = []): UserWord
    {
        return UserWord::create(array_merge([
            'user_id' => $user->id,
            'word' => $word,
            'source' => 'quiz_new',
            'ease_factor' => 1.8,
            'interval_days' => 3,
            'repetitions' => 2,
            'lapses' => 1,
            'next_review_at' => now()->subDays(3),
            'last_reviewed_at' => now()->subDays(6),
        ], $attrs));
    }

    private function freshIn(array $picked, array $fresh): int
    {
        return count(array_intersect($picked, $fresh));
    }

    public function test_searched_words_take_up_to_half_of_a_quiz(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $fresh = [];
        for ($i = 0; $i < 8; $i++) {
            $fresh[] = $this->searched($user, "looked{$i}")->word;
        }
        for ($i = 0; $i < 12; $i++) {
            $this->card($user, "weak{$i}");
        }
        // smart mix also draws a few new words from the dictionary
        for ($i = 0; $i < 5; $i++) {
            Word::create(['word' => "unseen{$i}", 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => $i + 1]);
        }

        foreach (['smart', 'weak'] as $mode) {
            $picked = app(QuizBuilderService::class)->selectWords(10, $mode);

            $this->assertCount(10, $picked, $mode);
            $this->assertCount(10, array_unique($picked), "{$mode}: no duplicates");
            $this->assertSame(5, $this->freshIn($picked, $fresh), "{$mode}: capped at half");
        }
    }

    public function test_every_searched_word_is_used_when_there_are_few(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->searched($user, 'invoice');
        $this->searched($user, 'warranty');
        for ($i = 0; $i < 12; $i++) {
            $this->card($user, "weak{$i}");
        }

        $picked = app(QuizBuilderService::class)->selectWords(10, 'weak');

        $this->assertContains('invoice', $picked);
        $this->assertContains('warranty', $picked);
    }

    public function test_tested_searched_words_lose_their_priority(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->searched($user, 'reviewed', ['last_reviewed_at' => now()->subDay()]);
        $this->searched($user, 'failed', ['lapses' => 1]);
        $this->searched($user, 'paused', ['suspended_at' => now()]);
        $this->searched($user, 'fresh');

        $this->assertSame(['fresh'], UserWord::forUser()->freshSearch()->pluck('word')->all());
    }

    public function test_searched_words_lead_the_review_queue_and_are_never_pushed_back(): void
    {
        $user = User::factory()->create();
        config(['srs.daily_capacity' => 5]);
        $srs = app(SpacedRepetitionService::class);

        // far more urgent by overdueness, but they must not crowd out new lookups
        for ($i = 0; $i < 8; $i++) {
            $this->card($user, "weak{$i}");
        }
        $this->searched($user, 'invoice');
        $this->searched($user, 'warranty');

        $queue = $srs->dueWords($user->id)->pluck('word')->all();

        $this->assertCount(5, $queue);
        $this->assertSame(['invoice', 'warranty'], array_slice($queue, 0, 2));
        $this->assertTrue(
            UserWord::forUser($user->id)->freshSearch()->get()
                ->every(fn ($w) => $w->next_review_at <= now()),
            'rebalance left the searched words due today'
        );
    }

    public function test_a_second_quiz_in_one_sitting_still_drills_real_reviews(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['srs.daily_capacity' => 3, 'srs.new_word_share' => 0.2]);

        for ($i = 0; $i < 25; $i++) {
            $this->card($user, "word{$i}", ['next_review_at' => now()->subDays(5)]);
        }

        $builder = app(QuizBuilderService::class);

        $first = $builder->selectWords(10, 'smart');
        $this->persistAsPendingQuiz($user, $first);
        $second = $builder->selectWords(10, 'smart');

        // The daily capacity caps what a *day* is expected to carry, not what
        // one sitting can drill: the review portion is filled from the due
        // queue past that cap, and pullForward() makes more cards due when the
        // queue itself runs out. Either way every slot lands on a due card,
        // which is the only kind quizCorrect() will reschedule.
        foreach ([$first, $second] as $i => $picked) {
            $due = UserWord::forUser($user->id)
                ->whereIn('word', $picked)
                ->where('next_review_at', '<=', now())
                ->count();

            $this->assertSame(8, $due, 'quiz '.($i + 1).' drills a full review portion');
        }

        $this->assertEmpty(
            array_intersect($first, $second),
            'the second quiz asks about different words than the one still open'
        );
    }

    /** Mirrors QuizController@store: the quiz is saved before it is answered. */
    private function persistAsPendingQuiz(User $user, array $words): Quiz
    {
        $quiz = Quiz::create([
            'user_id' => $user->id, 'title' => 'open', 'type' => 'vocab_mc',
            'status' => 'pending', 'total' => count($words),
        ]);
        foreach ($words as $word) {
            QuizQuestion::create([
                'quiz_id' => $quiz->id, 'word' => $word,
                'question' => 'q', 'options' => ['a'], 'correct_answer' => 'A',
            ]);
        }

        return $quiz;
    }

    public function test_a_long_sitting_keeps_finding_new_material(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['srs.daily_capacity' => 4, 'srs.new_word_share' => 0.2]);
        $srs = app(SpacedRepetitionService::class);
        $builder = app(QuizBuilderService::class);

        for ($i = 0; $i < 60; $i++) {
            $this->card($user, "word{$i}", ['next_review_at' => now()->subDays(5)]);
        }

        $seen = [];
        for ($round = 0; $round < 4; $round++) {
            $picked = $builder->selectWords(10, 'smart');
            $seen = array_merge($seen, $picked);

            // answered right, as a finished quiz would
            foreach ($picked as $word) {
                $srs->quizCorrect($word);
            }
        }

        $this->assertSame(
            count($seen),
            count(array_unique($seen)),
            'a sitting of four quizzes never repeats a word it already got right'
        );
    }

    public function test_a_word_missed_today_still_comes_back_around(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['srs.daily_capacity' => 4, 'srs.new_word_share' => 0.2]);
        $srs = app(SpacedRepetitionService::class);
        $builder = app(QuizBuilderService::class);

        for ($i = 0; $i < 20; $i++) {
            $this->card($user, "word{$i}", ['next_review_at' => now()->subDays(5)]);
        }
        // enough unseen words that the new-word share never has to fall back
        // on the library — that fallback is what would re-serve a cleared word
        for ($i = 0; $i < 10; $i++) {
            Word::create(['word' => "core{$i}", 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => $i + 1]);
        }

        $srs->quizCorrect('word0');
        $srs->demote('word1', 'quiz_weak');

        // A miss is not promoted to the front — cards further past their due
        // date are still more urgent — but it stays in the pool and surfaces
        // as the sitting works through them.
        $seen = [];
        for ($round = 0; $round < 2; $round++) {
            $picked = $builder->selectWords(10, 'smart');
            $seen = array_merge($seen, $picked);
            foreach ($picked as $word) {
                $srs->quizCorrect($word);
            }
        }

        $this->assertContains('word1', $seen, 'a miss comes back round in the same sitting');
        $this->assertNotContains('word0', $seen, 'a word already answered right is not re-drilled');
    }

    public function test_a_quiz_built_before_the_last_one_is_finished_uses_other_words(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['srs.daily_capacity' => 4, 'srs.new_word_share' => 0.2]);

        for ($i = 0; $i < 30; $i++) {
            $this->card($user, "word{$i}", ['next_review_at' => now()->subDays(5)]);
        }
        // the two the unfinished quiz holds are the most overdue of all, so
        // they would lead the queue if they were not being held back
        foreach (['word0', 'word1'] as $word) {
            UserWord::forUser($user->id)->where('word', $word)
                ->update(['next_review_at' => now()->subDays(40)]);
        }

        $pending = Quiz::create(['user_id' => $user->id, 'title' => 'unfinished', 'type' => 'vocab_mc', 'status' => 'pending', 'total' => 2]);
        foreach (['word0', 'word1'] as $word) {
            QuizQuestion::create(['quiz_id' => $pending->id, 'word' => $word, 'question' => 'q', 'options' => ['a'], 'correct_answer' => 'A']);
        }

        $picked = app(QuizBuilderService::class)->selectWords(10, 'smart');

        $this->assertNotContains('word0', $picked);
        $this->assertNotContains('word1', $picked);

        // but one abandoned weeks ago must not keep its words out for good.
        // created_at is not fillable, so go through the query builder.
        Quiz::whereKey($pending->id)->update(['created_at' => now()->subDays(9)]);

        $later = app(QuizBuilderService::class)->selectWords(10, 'smart');

        $this->assertContains('word0', $later, 'an old abandoned quiz releases its words');
        $this->assertContains('word1', $later);
    }

}
