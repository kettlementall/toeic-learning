<?php

namespace Tests\Feature;

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
}
