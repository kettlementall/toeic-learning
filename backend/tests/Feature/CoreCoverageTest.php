<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use App\Services\CoreCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's progress through the curated TOEIC list.
 */
class CoreCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function core(string $word, int $rank): void
    {
        Word::create(['word' => $word, 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => $rank]);
    }

    private function own(User $user, string $word, array $attrs = []): void
    {
        UserWord::create(array_merge(['user_id' => $user->id, 'word' => $word, 'source' => 'quiz_new', 'next_review_at' => today()], $attrs));
    }

    public function test_coverage_counts_learning_and_mature_words_by_rank_band(): void
    {
        $user = User::factory()->create();
        $this->core('offer', 1);
        $this->core('invoice', 2);
        $this->core('agenda', 3);
        $this->core('lease', 201);
        $this->core('tenant', 202);

        $this->own($user, 'offers', ['interval_days' => 30]);                         // inflection, mature
        $this->own($user, 'invoice', ['interval_days' => 3]);                         // learning
        $this->own($user, 'lease', ['interval_days' => 40, 'suspended_at' => now()]); // suspended: learning, not mature
        $this->own($user, 'merchandise');                                             // not on the list
        $this->own(User::factory()->create(), 'agenda', ['interval_days' => 30]);     // someone else's

        $cov = app(CoreCoverageService::class)->forUser($user->id);

        $this->assertSame(5, $cov['total']);
        $this->assertSame(1, $cov['mature']);
        $this->assertSame(2, $cov['learning']);
        $this->assertSame(4, $cov['library']);
        $this->assertSame(3, $cov['in_core']);
        $this->assertSame([
            ['from' => 1, 'to' => 3, 'total' => 3, 'learning' => 1, 'mature' => 1],
            ['from' => 201, 'to' => 202, 'total' => 2, 'learning' => 1, 'mature' => 0],
        ], $cov['bands']);
    }

    public function test_dashboard_shows_core_progress(): void
    {
        $user = User::factory()->create();
        $this->core('offer', 1);
        $this->core('invoice', 2);
        $this->own($user, 'offer', ['interval_days' => 30]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('TOEIC Core Vocabulary')
            ->assertSee('#1–2')
            ->assertSee('1/2');
    }

    public function test_dashboard_skips_the_card_without_a_list(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertDontSee('TOEIC Core Vocabulary');
    }
}
