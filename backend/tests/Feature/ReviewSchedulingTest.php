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
        config(['srs.daily_capacity' => 10]);
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

    public function test_overdue_backlog_is_spread_across_days_within_capacity(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 35; $i++) {
            $this->card($user, "word{$i}", ['next_review_at' => now()->subDays(20)]);
        }

        $this->assertSame(35, $this->srs->backlogCount($user->id));

        $moved = $this->srs->rebalance($user->id);

        $this->assertSame(25, $moved);
        $this->assertSame(10, $this->srs->backlogCount($user->id), 'today is left at capacity');

        // and no future day is over capacity either
        $perDay = UserWord::forUser($user->id)
            ->where('next_review_at', '>', now())
            ->selectRaw('DATE(next_review_at) d, COUNT(*) c')
            ->groupBy('d')
            ->pluck('c');

        $this->assertTrue($perDay->every(fn ($c) => $c <= 10), 'no day exceeds the daily capacity');
    }

    public function test_review_session_is_never_larger_than_the_daily_capacity(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 40; $i++) {
            $this->card($user, "word{$i}");
        }

        $this->assertCount(10, $this->srs->dueWords($user->id));
        $this->assertSame(10, $this->srs->dueCount($user->id));
        $this->assertSame(10, $this->srs->backlogCount($user->id), 'the rest was rescheduled, not left overdue');
    }

    public function test_the_most_urgent_cards_are_kept_for_today(): void
    {
        $user = User::factory()->create();
        config(['srs.daily_capacity' => 1]);

        // 2 days late on a 1-day interval is far more urgent than
        // 2 days late on a 100-day interval
        $urgent = $this->card($user, 'urgent', ['interval_days' => 1, 'next_review_at' => now()->subDays(2)]);
        $this->card($user, 'relaxed', ['interval_days' => 100, 'next_review_at' => now()->subDays(2)]);

        $queue = $this->srs->dueWords($user->id);

        $this->assertCount(1, $queue);
        $this->assertSame($urgent->id, $queue->first()->id);
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

    public function test_cards_with_the_same_interval_do_not_all_land_on_one_day(): void
    {
        $user = User::factory()->create();
        config(['srs.daily_capacity' => 100]);

        for ($i = 0; $i < 20; $i++) {
            $card = $this->card($user, "word{$i}", [
                'interval_days' => 30,
                'last_reviewed_at' => now(),
                'next_review_at' => now(),
            ]);
            $this->srs->grade($card, 4);
        }

        $distinctDays = UserWord::forUser($user->id)
            ->selectRaw('DATE(next_review_at) d')
            ->distinct()
            ->count();

        $this->assertGreaterThan(5, $distinctDays, 'load balancing spread the batch out');
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
        config(['srs.new_word_share' => 0.2, 'srs.daily_capacity' => 8]);

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

    public function test_pulling_forward_a_deferred_card_keeps_its_interval(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        // interval ran out 24 days ago; it sits in the future only because
        // rebalance moved it off a full day
        $card = $this->card($user, 'invoice', [
            'interval_days' => 6,
            'repetitions' => 2,
            'last_reviewed_at' => now()->subDays(30),
            'next_review_at' => now()->addDay()->startOfDay(),
        ]);

        $pulled = $this->srs->pullForward(1);

        $this->assertSame(['invoice'], $pulled);
        $card->refresh();
        $this->assertSame(6, $card->interval_days, 'a deferred card keeps what it earned');
        $this->assertTrue($card->next_review_at->isToday());

        // so the late-review credit grows from the scheduled interval plus half
        // the overdue time: (6 + 24/2) * 2.5 = 45. Re-anchoring the interval to
        // the whole 30-day gap would have handed it 75 for free.
        $this->srs->quizCorrect('invoice');
        $this->assertSame(45, $card->refresh()->interval_days);
    }

    public function test_pulling_forward_an_early_card_re_anchors_its_interval(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $card = $this->card($user, 'warranty', [
            'interval_days' => 38,
            'repetitions' => 5,
            'lapses' => 1,
            'ease_factor' => 2.3,
            'last_reviewed_at' => now()->subDays(5),
            'next_review_at' => now()->addDays(33)->startOfDay(),
        ]);

        $this->srs->pullForward(1);

        $card->refresh();
        $this->assertSame(5, $card->interval_days, 'an early drill only earns the time elapsed');
        $this->assertTrue($card->next_review_at->isToday());
        $this->assertSame(1, $card->lapses, 'not a failure');
        $this->assertSame(2.3, (float) $card->ease_factor);
    }

    public function test_pull_forward_measures_overdueness_against_the_cards_own_interval(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // ten times its own interval overdue, and a one-day card: whatever else
        // is waiting, this is the one closest to being forgotten
        $this->card($user, 'struggling', [
            'ease_factor' => 1.5,
            'interval_days' => 1,
            'repetitions' => 1,
            'last_reviewed_at' => now()->subDays(10),
            'next_review_at' => now()->addDay()->startOfDay(),
        ]);
        // barely past a 100-day interval, and sitting nearer in the calendar
        $this->card($user, 'mature', [
            'ease_factor' => 2.8,
            'interval_days' => 100,
            'repetitions' => 8,
            'last_reviewed_at' => now()->subDays(101),
            'next_review_at' => now()->addDays(5)->startOfDay(),
        ]);

        // ordering by due date, or by overdueness against it, would put the
        // mature card first: dividing a small lateness by a 100-day interval
        // looks less urgent than dividing a day's lateness by one day
        $this->assertSame(['struggling', 'mature'], $this->srs->pullForward(2));
    }

    public function test_pull_forward_takes_deferred_cards_before_early_ones(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach ([1, 2, 3] as $i) {
            $this->card($user, "deferred{$i}", [
                'interval_days' => 6,
                'repetitions' => 2,
                'last_reviewed_at' => now()->subDays(30),
                'next_review_at' => now()->addDays($i)->startOfDay(),
            ]);
        }
        $early = $this->card($user, 'early', [
            'interval_days' => 60,
            'repetitions' => 5,
            'last_reviewed_at' => now()->subDays(2),
            'next_review_at' => now()->addDays(58)->startOfDay(),
        ]);

        $pulled = $this->srs->pullForward(3);

        $this->assertSame(['deferred1', 'deferred2', 'deferred3'], $pulled);
        $this->assertSame(60, $early->refresh()->interval_days, 'the early card was left alone');
        $this->assertFalse($early->next_review_at->isToday());
    }

    public function test_a_smart_quiz_drills_the_due_queue_first(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['srs.daily_capacity' => 5, 'srs.new_word_share' => 0.2]);

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

    public function test_building_a_quiz_rebalances_the_schedule(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['srs.daily_capacity' => 5, 'srs.new_word_share' => 0.2]);

        for ($i = 0; $i < 30; $i++) {
            $this->card($user, "word{$i}");
        }
        $this->assertSame(30, $this->srs->backlogCount($user->id));

        app(QuizBuilderService::class)->selectWords(10, 'smart');

        // nothing else in this deployment ever calls rebalance, so the quiz
        // build is what has to flatten the calendar
        $perDay = UserWord::forUser($user->id)
            ->active()
            ->where('next_review_at', '>', now())
            ->get()
            ->groupBy(fn ($w) => $w->next_review_at->toDateString())
            ->map->count();

        foreach ($perDay as $day => $count) {
            $this->assertLessThanOrEqual(5, $count, "day {$day} is over capacity");
        }
    }
    public function test_pull_forward_prefers_the_deferred_pile_over_tomorrows_new_words(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // just taught, comes due tomorrow — nearer than anything below
        $taught = $this->card($user, 'fresh', [
            'interval_days' => 1,
            'repetitions' => 1,
            'last_reviewed_at' => now(),
            'next_review_at' => now()->addDay()->startOfDay(),
        ]);
        // overdue for weeks, parked further out by rebalance
        foreach ([5, 6] as $i => $days) {
            $this->card($user, "parked{$i}", [
                'interval_days' => 6,
                'repetitions' => 3,
                'last_reviewed_at' => now()->subDays(40),
                'next_review_at' => now()->addDays($days)->startOfDay(),
            ]);
        }

        $pulled = $this->srs->pullForward(2);

        $this->assertSame(['parked0', 'parked1'], $pulled, 'the deferred pile comes first');
        $this->assertFalse($taught->refresh()->next_review_at->isToday(), 'a word just taught is left alone');
    }
}
