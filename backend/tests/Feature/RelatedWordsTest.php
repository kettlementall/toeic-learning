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

    /** Already sense-checked and listed unless a test says otherwise, so the request has nothing else to heal. */
    private function word(string $word, ?array $related = [], ?string $zh = null): Word
    {
        return Word::create(['word' => $word, 'definition_en' => $word, 'definition_zh' => $zh, 'related' => $related, 'senses_checked_at' => now()]);
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

        $this->word('postpone', null);
        $this->word('acquire', null);
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
        $this->own($this->word('delays', [], '延誤'));                 // inflection of "delay"
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

    /** Answers whichever generator the prompt belongs to (bodies escape Chinese, so match on the ASCII examples). */
    private function fakeGenerators(array $meanings, array $related): void
    {
        Http::fake(['api.anthropic.com/*' => function ($request) use ($meanings, $related) {
            $payload = str_contains($request->body(), 'five-year lease') ? $meanings : $related;

            return Http::response(['content' => [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]], 200);
        }]);
    }

    public function test_the_card_request_repairs_unchecked_meanings_and_returns_them(): void
    {
        $this->fakeGenerators(
            ['industry' => [['pos' => 'noun', 'definition_en' => 'a sector of the economy', 'definition_zh' => '產業', 'example' => 'She works in the auto industry.']]],
            [],
        );
        $industry = Word::create(['word' => 'industry', 'definition_zh' => '勤勉', 'related' => []]);
        $this->word('invoice'); // already checked: not sent for repair

        $res = $this->related(['industry', 'invoice']);
        $meanings = $this->actingAs($this->user)->getJson('/words/related?q[]=industry')->json('meanings');

        $this->assertSame('產業', $industry->fresh()->definition_zh);
        $this->assertNotNull($industry->fresh()->senses_checked_at);
        Http::assertSentCount(1); // the second request found nothing left to repair
        Http::assertSent(fn ($r) => ! str_contains($r->body(), 'invoice'));
        $this->assertSame([], $meanings);
        $this->assertSame([], $res['industry']);
    }

    public function test_repaired_meanings_reach_the_open_cards(): void
    {
        $this->fakeGenerators(
            ['industry' => [['pos' => 'noun', 'definition_en' => 'a sector', 'definition_zh' => '產業', 'example' => 'ex']]],
            [],
        );
        Word::create(['word' => 'industry', 'definition_zh' => '勤勉', 'related' => []]);

        $this->actingAs($this->user)->getJson('/words/related?q[]=industry')
            ->assertJsonPath('meanings.industry.zh', '產業')
            ->assertJsonPath('meanings.industry.pos', 'noun')
            ->assertJsonPath('meanings.industry.example', 'ex')
            ->assertJsonPath('meanings.industry.meanings.0.definition_zh', '產業');
    }

    public function test_known_words_without_a_list_get_one_on_any_card_request(): void
    {
        // learned only through quizzes: never a card, so never listed
        $this->fakeGenerators([], ['delay' => [['word' => 'postpone', 'note' => 'n']]]);
        $this->own($this->word('delay', null));
        $this->word('merchandise');

        $this->related(['merchandise']);

        $this->assertSame([['word' => 'postpone', 'note' => 'n']], Word::where('word', 'delay')->first()->related);
    }

    public function test_commands_reach_the_whole_catalog_only_when_asked(): void
    {
        $this->fakeGenerators(
            ['agenda' => [['pos' => 'noun', 'definition_en' => 'a list of items', 'definition_zh' => '議程', 'example' => null]]],
            ['agenda' => []],
        );
        // in no one's library: the curated list new words are drawn from
        $agenda = Word::create(['word' => 'agenda', 'source' => Word::SOURCE_TOEIC_CORE, 'definition_zh' => '議程']);

        $this->artisan('words:fix-meanings')->assertSuccessful();
        $this->artisan('words:backfill-related')->assertSuccessful();
        $this->assertNull($agenda->fresh()->senses_checked_at);
        $this->assertNull($agenda->fresh()->related);

        $this->artisan('words:fix-meanings', ['--catalog' => true])->assertSuccessful();
        $this->artisan('words:backfill-related', ['--catalog' => true])->assertSuccessful();
        $this->assertNotNull($agenda->fresh()->senses_checked_at);
        $this->assertSame([], $agenda->fresh()->related);
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
