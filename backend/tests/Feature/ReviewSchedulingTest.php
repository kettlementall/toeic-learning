<?php

namespace Tests\Feature;

use App\Models\ReviewLog;
use App\Models\User;
use App\Models\UserWord;
use App\Services\QuizBuilderService;
use App\Services\SpacedRepetitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private SpacedRepetitionService $srs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->srs = app(SpacedRepetitionService::class);
    }

    private function card(User $user, string $word, array $attrs = []): UserWord
    {
        return UserWord::create(array_merge([
            'user_id' => $user->id,
            'word' => $word,
            'source' => 'manual',
            'ease_factor' => 2.5,
            'interval_days' => 10,
            'repetitions' => 4,
            'next_review_at' => now()->subDay(),
            'last_reviewed_at' => now()->subDays(11),
        ], $attrs));
    }

    public function test_the_most_urgent_cards_come_first(): void
    {
        $user = User::factory()->create();

        // 2 days late on a 1-day interval is far more urgent than
        // 2 days late on a 100-day interval
        $urgent = $this->card($user, 'urgent', ['interval_days' => 1, 'next_review_at' => now()->subDays(2)]);
        $this->card($user, 'relaxed', ['interval_days' => 100, 'next_review_at' => now()->subDays(2)]);

        $queue = $this->srs->dueWords($user->id);

        $this->assertCount(2, $queue, 'nothing is held back — the queue is everything ripe');
        $this->assertSame($urgent->id, $queue->first()->id);
    }

    public function test_a_card_not_yet_ripe_stays_out_of_the_queue(): void
    {
        $user = User::factory()->create();

        $this->card($user, 'ripe', ['next_review_at' => now()->subDay()]);
        $this->card($user, 'unripe', ['next_review_at' => now()->addDays(3)]);

        // doing more quizzes does not make a card ripe sooner — forgetting
        // runs on elapsed time, so nothing drags a future card into today
        $this->assertSame(['ripe'], $this->srs->dueWords($user->id)->pluck('word')->all());
    }

    public function test_a_miss_is_not_buried_under_the_library(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // a big library of stale cards, all further past due than anything
        // demoted today can be: without relearning priority the miss sinks
        for ($i = 0; $i < 200; $i++) {
            $this->card($user, "old{$i}", ['interval_days' => 1, 'next_review_at' => now()->subDays(30)]);
        }
        $this->card($user, 'missed', ['next_review_at' => now()->subDay()]);

        $this->srs->demote('missed', 'quiz_weak');

        $queue = $this->srs->dueWords($user->id, 10)->pluck('word')->all();

        $this->assertContains('missed', $queue, 'the card that just failed is at the back of a 200-card queue');
    }

    public function test_recalling_an_overdue_card_credits_the_extra_time(): void
    {
        $user = User::factory()->create();
        $card = $this->card($user, 'contract', [
            'interval_days' => 10,
            'repetitions' => 4,
            'ease_factor' => 2.5,
            'last_reviewed_at' => now()->subDays(30),
            'next_review_at' => now()->subDays(20),
        ]);

        $this->srs->grade($card, 4);

        // without the late bonus this would be 10 * 2.5 = 25;
        // credited base is 10 + (20 overdue / 2) = 20, so 20 * 2.5 = 50
        $this->assertSame(50, $card->interval_days);
    }

    public function test_a_word_that_keeps_failing_is_pulled_out_of_the_rotation(): void
    {
        $user = User::factory()->create();
        config(['srs.leech_suspend_at' => 3]);

        $card = $this->card($user, 'marginal', ['lapses' => 2]);
        $this->srs->grade($card, 1);

        $this->assertNotNull($card->suspended_at);
        $this->assertSame(0, $this->srs->backlogCount($user->id), 'suspended words leave the queue');
        $this->assertCount(0, $this->srs->dueWords($user->id));
    }

    public function test_a_resumed_word_relearns_from_scratch(): void
    {
        $user = User::factory()->create();
        $card = $this->card($user, 'marginal', [
            'lapses' => 9,
            'suspended_at' => now(),
            'interval_days' => 40,
            'repetitions' => 6,
        ]);

        $this->srs->resume($card);

        $this->assertNull($card->suspended_at);
        $this->assertSame(0, $card->lapses);
        $this->assertSame(0, $card->repetitions);
        $this->assertSame(1, $card->interval_days);
        $this->assertSame(1, $this->srs->backlogCount($user->id));
    }

    public function test_a_correct_quiz_answer_on_a_card_not_yet_due_keeps_its_schedule(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $due = now()->addDays(5)->startOfDay();
        $card = $this->card($user, 'contract', [
            'interval_days' => 6,
            'repetitions' => 2,
            'next_review_at' => $due,
            'last_reviewed_at' => now()->subDay(),
        ]);

        $this->srs->quizCorrect('contract');

        $card->refresh();
        $this->assertSame(6, $card->interval_days, 'an early peek does not stretch the interval');
        $this->assertSame(2, $card->repetitions);
        $this->assertTrue($card->next_review_at->equalTo($due));
        $this->assertSame(
            1,
            ReviewLog::where('user_word_id', $card->id)
                ->where('quality', SpacedRepetitionService::QUIZ_CORRECT_QUALITY)
                ->count(),
            'still counted for stats'
        );
    }

    public function test_a_correct_quiz_answer_on_a_due_or_never_reviewed_card_counts_as_a_review(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $due = $this->card($user, 'invoice', ['interval_days' => 6, 'repetitions' => 2]);
        $untested = $this->card($user, 'warranty', [
            'source' => 'search',
            'interval_days' => 0,
            'repetitions' => 0,
            'next_review_at' => now()->addDays(4),
            'last_reviewed_at' => null,
        ]);

        $this->srs->quizCorrect('invoice');
        $this->srs->quizCorrect('warranty');

        $this->assertSame(3, $due->refresh()->repetitions);
        $this->assertSame(2.5, (float) $due->ease_factor, 'recognition does not raise ease');
        $this->assertSame(1, $untested->refresh()->repetitions);
        $this->assertNotNull($untested->last_reviewed_at);
    }

    public function test_failing_the_same_word_twice_in_one_day_costs_one_lapse(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = $this->card($user, 'contract', ['lapses' => 0, 'ease_factor' => 2.5]);

        $this->srs->demote('contract');          // wrong in a quiz
        $card->refresh();
        $this->assertSame(1, $card->lapses);
        $this->assertSame(2.3, $card->ease_factor);

        $this->srs->grade($card, 1);              // then "Forgot" in review
        $this->srs->demote('contract');           // and wrong in another quiz

        $card->refresh();
        $this->assertSame(1, $card->lapses, 'one bad day is one lapse');
        $this->assertSame(2.3, $card->ease_factor, 'and one ease drop');
        $this->assertSame(0, $card->repetitions, 'but it is still relearned');
    }

    public function test_ai_review_suggestions_are_queued_without_counting_as_failures(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $existing = $this->card($user, 'contract', [
            'lapses' => 2,
            'ease_factor' => 2.4,
            'interval_days' => 20,
            'repetitions' => 4,
            'next_review_at' => now()->addDays(15),
            'last_reviewed_at' => now()->subDays(5),
        ]);

        $new = $this->srs->queueForReview('warranty');
        $this->srs->queueForReview('contract');

        $this->assertSame(0, $new->refresh()->lapses);
        $this->assertSame('ai_review', $new->source);
        $this->assertTrue($new->next_review_at->lte(now()), 'new word is due today');

        $existing->refresh();
        $this->assertSame(2, $existing->lapses);
        $this->assertSame(2.4, $existing->ease_factor);
        $this->assertSame(4, $existing->repetitions);
        $this->assertTrue($existing->next_review_at->lte(now()), 'pulled forward to today');
        $this->assertSame(5, $existing->interval_days, 'interval re-anchored to the real gap');
        $this->assertSame(0, ReviewLog::count(), 'no failure logged');
    }

    public function test_every_quiz_carries_new_words_no_matter_the_backlog(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['srs.new_word_share' => 0.2]);

        $allowance = function (int $n) {
            $qb = app(QuizBuilderService::class);
            $m = new \ReflectionMethod($qb, 'newWordAllowance');
            $m->setAccessible(true);

            return $m->invoke($qb, $n);
        };

        $this->assertSame(2, $allowance(10), 'an empty library gets the full share');

        // the old gate zeroed this out; a backlog is the schedule's problem now
        for ($i = 0; $i < 200; $i++) {
            $this->card($user, "word{$i}");
        }
        $this->assertSame(2, $allowance(10), 'a backlog does not starve new material');

        $this->assertSame(1, $allowance(5), 'a quiz with a slot to spare always has one');
        $this->assertSame(0, $allowance(3), 'but a tiny quiz is all review');
    }

    public function test_a_correct_quiz_answer_does_not_raise_the_ease_factor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = $this->card($user, 'invoice', [
            'ease_factor' => 2.5,
            'interval_days' => 10,
            'repetitions' => 4,
            'next_review_at' => now()->subDay(),
            'last_reviewed_at' => now()->subDays(10),
        ]);

        $this->srs->quizCorrect('invoice');

        $card->refresh();
        $this->assertSame(2.5, (float) $card->ease_factor, 'four options is recognition, not recall');
        $this->assertGreaterThan(10, $card->interval_days, 'the interval still grows');
    }

    public function test_a_smart_quiz_drills_the_due_queue_first(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['srs.new_word_share' => 0.2]);

        foreach (['invoice', 'shipment', 'warranty'] as $i => $word) {
            $this->card($user, $word, ['next_review_at' => now()->subDays(10 - $i)]);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->card($user, "later{$i}", [
                'interval_days' => 60,
                'last_reviewed_at' => now()->subDay(),
                'next_review_at' => now()->addDays(59),
            ]);
        }

        $picked = app(QuizBuilderService::class)->selectWords(10, 'smart');

        foreach (['invoice', 'shipment', 'warranty'] as $word) {
            $this->assertContains($word, $picked, 'due cards come first');
        }
    }

}
