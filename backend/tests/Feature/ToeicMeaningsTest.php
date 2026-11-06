<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Meanings are chosen for their TOEIC sense, not taken as the dictionary's
 * first definition — which for "lease" is a dialect word for gathering.
 */
class ToeicMeaningsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-key']);
        $this->actingAs(User::factory()->create());
    }

    /** dictionaryapi's real shape for "lease": the dialect homograph comes first */
    private function leaseEntries(): array
    {
        return [
            ['word' => 'lease', 'phonetics' => [], 'meanings' => [
                ['partOfSpeech' => 'verb', 'definitions' => [['definition' => '(chiefly dialectal) To gather.']]],
            ]],
            ['word' => 'lease', 'phonetics' => [], 'meanings' => [
                ['partOfSpeech' => 'noun', 'definitions' => [['definition' => 'A contract granting use of property for a period.']]],
                ['partOfSpeech' => 'verb', 'definitions' => [['definition' => 'To rent out property under a lease.']]],
            ]],
        ];
    }

    private function claude(array|string $payload, int $status = 200): \Closure
    {
        return fn () => Http::response(['content' => [['text' => is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE)]]], $status);
    }

    public function test_lookup_keeps_the_senses_chosen_from_every_homograph(): void
    {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response($this->leaseEntries(), 200),
            'api.anthropic.com/*' => $this->claude([
                'meanings' => [
                    ['pos' => 'noun', 'definition_en' => 'a rental contract', 'definition_zh' => '租約', 'example' => 'We signed a lease.'],
                    ['pos' => 'verb', 'definition_en' => 'to rent out', 'definition_zh' => '出租', 'example' => null],
                ],
                'toeic_note' => 'sign a lease',
                'mnemonic' => 'm',
            ]),
        ]);

        $this->getJson('/words/lookup?q=lease')->assertOk();

        $word = Word::where('word', 'lease')->first();
        $this->assertSame('租約', $word->definition_zh);
        $this->assertSame(['noun', 'verb'], array_column($word->meanings, 'pos'));
        $this->assertSame('We signed a lease.', $word->example);
        $this->assertNotNull($word->senses_checked_at);

        // the model saw the second entry, not just the dialect one
        Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic') && str_contains($r->body(), 'A contract granting use'));
    }

    public function test_a_failed_choice_keeps_the_dictionary_meanings_unchecked(): void
    {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response($this->leaseEntries(), 200),
            'api.anthropic.com/*' => Http::response('overloaded', 529),
        ]);

        $this->getJson('/words/lookup?q=lease')->assertOk();

        $word = Word::where('word', 'lease')->first();
        $this->assertSame('(chiefly dialectal) To gather.', $word->definition_en);
        $this->assertNull($word->senses_checked_at, 'left for words:fix-meanings');
    }

    public function test_fix_meanings_repairs_unchecked_library_words_in_one_call(): void
    {
        $user = User::factory()->create();
        $lease = Word::create(['word' => 'lease', 'definition_en' => '(chiefly dialectal) To gather.', 'definition_zh' => '收集',
            'meanings' => [['pos' => 'verb', 'definition_en' => '(chiefly dialectal) To gather.', 'definition_zh' => '收集']]]);
        $industry = Word::create(['word' => 'industry', 'definition_zh' => '勤勉', 'meanings' => []]);
        $checked = Word::create(['word' => 'invoice', 'definition_zh' => '發票', 'senses_checked_at' => now()->subDay()]);
        $skipped = Word::create(['word' => 'agenda', 'definition_zh' => '議程']);
        foreach ([$lease, $industry, $checked, $skipped] as $w) {
            UserWord::create(['user_id' => $user->id, 'word_id' => $w->id, 'word' => $w->word, 'source' => 'search', 'next_review_at' => today()]);
        }

        Http::fake(['api.anthropic.com/*' => $this->claude([
            'lease' => [['pos' => 'noun', 'definition_en' => 'a rental contract', 'definition_zh' => '租約', 'example' => 'We signed a lease.']],
            'industry' => [['pos' => 'noun', 'definition_en' => 'a sector of the economy', 'definition_zh' => '產業', 'example' => null],
                ['pos' => 'noun', 'definition_en' => 'bad', 'definition_zh' => '']], // no Chinese: dropped
            // agenda left out by the model: stays unchecked for a rerun
        ])]);

        $this->artisan('words:fix-meanings', ['--user-id' => $user->id])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => ! str_contains($r->body(), 'invoice'));

        $lease->refresh();
        $this->assertSame('租約', $lease->definition_zh);
        $this->assertSame('noun', $lease->part_of_speech);
        $this->assertSame('We signed a lease.', $lease->example);
        $this->assertNotNull($lease->senses_checked_at);

        $industry->refresh();
        $this->assertSame('產業', $industry->definition_zh);
        $this->assertCount(1, $industry->meanings);

        $this->assertNull($skipped->fresh()->senses_checked_at);
        $this->assertSame('發票', $checked->fresh()->definition_zh);
    }
}
