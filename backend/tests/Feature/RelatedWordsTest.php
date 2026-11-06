<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Near-synonyms on the back of a flashcard: generated once per word by the AI,
 * shown only when the learner already knows them.
 */
class RelatedWordsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-key']);
        $this->user = User::factory()->create();
    }

    private function word(string $word, ?array $related = null, ?string $zh = null): Word
    {
        return Word::create(['word' => $word, 'definition_en' => $word, 'definition_zh' => $zh, 'related' => $related]);
    }

    private function own(Word $word, array $attrs = [], ?User $user = null): UserWord
    {
        return UserWord::create(array_merge([
            'user_id' => ($user ?? $this->user)->id, 'word_id' => $word->id, 'word' => $word->word,
            'source' => 'quiz_new', 'repetitions' => 2, 'next_review_at' => now()->addDays(3),
        ], $attrs));
    }

    private function related(array $terms): array
    {
        $query = implode('&', array_map(fn ($t) => 'q[]=' . urlencode($t), $terms));

        return $this->actingAs($this->user)->getJson("/words/related?{$query}")->assertOk()->json('related');
    }

    public function test_missing_lists_are_generated_in_one_call_and_kept(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['text' => json_encode([
            'postpone' => [['word' => 'delay', 'note' => 'n1'], ['word' => 'Postpone', 'note' => 'self']],
            'acquire' => [],
        ])]]], 200)]);

        $this->word('postpone');
        $this->word('acquire');
        $this->word('suspend', []); // asked before, none found: not asked again

        $this->related(['postpone', 'acquire', 'suspend']);
        $this->related(['postpone', 'acquire', 'suspend']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => ! str_contains($r->body(), 'suspend'));
        $this->assertSame([['word' => 'delay', 'note' => 'n1']], Word::where('word', 'postpone')->first()->related);
        $this->assertSame([], Word::where('word', 'acquire')->first()->related);
    }

    public function test_only_words_the_learner_knows_are_shown(): void
    {
        Http::fake();
        $card = $this->word('postpone', [
            ['word' => 'delay', 'note' => 'known'],
            ['word' => 'defer', 'note' => 'not yet passed'],
            ['word' => 'shelve', 'note' => 'suspended'],
            ['word' => 'hold off', 'note' => 'not in the library'],
            ['word' => 'reschedule', 'note' => 'someone else knows it'],
        ]);
        $this->own($card);
        $this->own($this->word('delays', null, '延誤'));               // inflection of "delay"
        $this->own($this->word('defer'), ['repetitions' => 0]);       // just added / relearning
        $this->own($this->word('shelve'), ['suspended_at' => now()]);
        $this->own($this->word('reschedule'), [], User::factory()->create());

        $this->assertSame(
            [['word' => 'delays', 'note' => 'known', 'zh' => '延誤']],
            $this->related(['postpone'])['postpone'],
        );
    }

    public function test_a_word_appears_once_it_is_first_answered_correctly(): void
    {
        Http::fake();
        $this->word('postpone', [['word' => 'delay', 'note' => 'n']]);
        $delay = $this->own($this->word('delay'), ['repetitions' => 0]);

        $this->assertSame([], $this->related(['postpone'])['postpone']);

        $delay->update(['repetitions' => 1]);

        $this->assertSame('delay', $this->related(['postpone'])['postpone'][0]['word']);
        Http::assertNothingSent();
    }

    public function test_pairs_are_found_from_either_side(): void
    {
        Http::fake();
        // the model listed delay under postpone, but nothing under delay
        $this->word('delay', []);
        $this->own($this->word('postpone', [['word' => 'delay', 'note' => 'delay is unplanned']]));

        $this->assertSame(
            [['word' => 'postpone', 'note' => 'delay is unplanned', 'zh' => null]],
            $this->related(['delay'])['delay'],
        );
    }

    public function test_review_page_asks_for_related_words(): void
    {
        $word = $this->word('lease', null);
        $this->own($word, ['next_review_at' => now()->subDay()]);

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertSee(route('words.related'), false)
            ->assertSee('Words you know with a similar meaning');
    }
}
