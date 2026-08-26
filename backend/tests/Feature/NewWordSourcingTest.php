<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use App\Services\ClaudeService;
use App\Services\QuizBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewWordSourcingTest extends TestCase
{
    use RefreshDatabase;

    private function newWords(int $n): array
    {
        $qb = app(QuizBuilderService::class);
        $m = new \ReflectionMethod($qb, 'newWords');
        $m->setAccessible(true);

        return $m->invoke($qb, $n, []);
    }

    public function test_the_curated_list_is_drawn_from_in_rank_order(): void
    {
        $this->actingAs(User::factory()->create());

        Word::create(['word' => 'rare', 'source' => 'api']);
        Word::create(['word' => 'contract', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 3]);
        Word::create(['word' => 'invoice', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 1]);
        Word::create(['word' => 'warranty', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 2]);

        $this->assertSame(['invoice', 'warranty'], $this->newWords(2), 'most frequent first');
    }

    public function test_uncurated_words_are_only_used_once_the_list_runs_out(): void
    {
        $this->actingAs(User::factory()->create());

        Word::create(['word' => 'invoice', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 1]);
        Word::create(['word' => 'obscure', 'source' => 'api']);

        $picked = $this->newWords(2);

        $this->assertSame('invoice', $picked[0]);
        $this->assertSame('obscure', $picked[1]);
    }

    public function test_words_already_owned_are_skipped(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Word::create(['word' => 'invoice', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 1]);
        Word::create(['word' => 'warranty', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 2]);
        UserWord::create([
            'user_id' => $user->id, 'word' => 'invoice', 'source' => 'manual',
            'next_review_at' => now()->addDay(),
        ]);

        $this->assertSame(['warranty'], $this->newWords(1));
    }

    public function test_claude_is_only_called_when_the_local_pool_is_exhausted(): void
    {
        $this->actingAs(User::factory()->create());

        Word::create(['word' => 'invoice', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 1]);

        $this->mock(ClaudeService::class, function ($mock) {
            $mock->shouldNotReceive('generateNewWords');
        });

        $this->assertSame(['invoice'], $this->newWords(1));
    }
}
