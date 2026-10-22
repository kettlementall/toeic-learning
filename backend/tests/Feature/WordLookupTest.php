<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The free dictionary (dictionaryapi.dev) goes down periodically. When it does,
 * a lookup must not be reported as a spelling mistake, and search should keep
 * working off Claude rather than failing outright.
 */
class WordLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-key']);
        $this->actingAs(User::factory()->create());
    }

    /** Claude answering with a full dictionary entry. */
    private function fakeClaudeEntry(): array
    {
        return [
            'api.anthropic.com/*' => Http::response(['content' => [['text' => json_encode([
                'valid' => true,
                'phonetic' => '/ˈmekənɪzəm/',
                'meanings' => [
                    ['pos' => 'noun', 'definition_en' => 'A system of parts working together.',
                        'definition_zh' => '機制；機械裝置', 'example' => 'The locking mechanism failed.'],
                ],
                'toeic_note' => '常見 payment mechanism、safety mechanism。',
                'synonyms' => 'system, device',
                'mnemonic' => 'mechan(機械)+ism(制度)→運作的機制。',
            ], JSON_UNESCAPED_UNICODE)]]], 200),
        ];
    }

    public function test_a_dictionary_outage_falls_back_to_claude(): void
    {
        Http::fake(array_merge([
            'api.dictionaryapi.dev/*' => Http::response('error code: 522', 522),
        ], $this->fakeClaudeEntry()));

        $res = $this->getJson('/words/lookup?q=mechanism');

        $res->assertOk();
        $res->assertJsonPath('word.definition_zh', '機制；機械裝置');
        $res->assertJsonPath('word.meanings.0.pos', 'noun');
        $res->assertJsonPath('added', true);
        $this->assertSame('ai', Word::where('word', 'mechanism')->value('source'));
    }

    public function test_several_words_share_one_ai_call_while_the_dictionary_is_down(): void
    {
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response('error code: 530', 530),
            'api.anthropic.com/*' => Http::response(['content' => [['text' => json_encode([
                'invoice' => ['phonetic' => '/ˈɪnvɔɪs/', 'meanings' => [
                    ['pos' => 'noun', 'definition_en' => 'A bill for goods.', 'definition_zh' => '發票'],
                ]],
                'warranty' => ['phonetic' => '/ˈwɒrənti/', 'meanings' => [
                    ['pos' => 'noun', 'definition_en' => 'A written guarantee.', 'definition_zh' => '保固'],
                ]],
                'zzqx' => ['meanings' => []],   // not a word: dropped
            ], JSON_UNESCAPED_UNICODE)]]], 200),
        ]);

        $found = app(\App\Services\DictionaryService::class)
            ->lookupMany(['invoice', 'warranty', 'zzqx', 'INVOICE']);

        $this->assertSame(['invoice', 'warranty'], array_keys($found));
        $this->assertSame('發票', $found['invoice']->definition_zh);
        $this->assertSame('ai', $found['warranty']->source);

        // the point of the batch: three unknown words, one AI round trip
        $aiCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'anthropic.com'))
            ->count();
        $this->assertSame(1, $aiCalls, 'the words were defined one call at a time');
    }

    public function test_a_batch_lookup_uses_the_cache_before_asking_anyone(): void
    {
        Word::create(['word' => 'invoice', 'definition_en' => 'A bill.', 'definition_zh' => '發票', 'source' => 'seed']);
        Http::fake([
            'api.dictionaryapi.dev/*' => Http::response('error code: 530', 530),
            'api.anthropic.com/*' => Http::response(['content' => [['text' => '{}']]], 200),
        ]);

        $found = app(\App\Services\DictionaryService::class)->lookupMany(['invoice']);

        $this->assertSame('發票', $found['invoice']->definition_zh);
        $this->assertSame('seed', $found['invoice']->source, 'a cached word was re-fetched and demoted');
        Http::assertNothingSent();
    }

    public function test_an_outage_with_no_fallback_reports_the_outage_not_a_typo(): void
    {
        config(['services.anthropic.api_key' => null]);
        Http::fake(['api.dictionaryapi.dev/*' => Http::response('error code: 522', 522)]);

        $res = $this->getJson('/words/lookup?q=mechanism');

        $res->assertStatus(503);
        $this->assertStringContainsString('temporarily unreachable', $res->json('error'));
    }

    public function test_a_word_the_dictionary_does_not_have_is_still_a_404(): void
    {
        Http::fake(array_merge([
            'api.dictionaryapi.dev/*' => Http::response(['title' => 'No Definitions Found'], 404),
        ], $this->fakeClaudeEntry()));

        $res = $this->getJson('/words/lookup?q=mechansim');

        $res->assertStatus(404);
        $this->assertStringContainsString('spelling', $res->json('error'));
    }

    public function test_looking_up_a_curated_word_does_not_demote_its_source(): void
    {
        Word::create([
            'word' => 'invoice',
            'source' => Word::SOURCE_TOEIC_CORE,
            'frequency_rank' => 1,
        ]);

        Http::fake(array_merge([
            'api.dictionaryapi.dev/*' => Http::response('error code: 522', 522),
        ], $this->fakeClaudeEntry()));

        $this->getJson('/words/lookup?q=invoice')->assertOk();

        $word = Word::where('word', 'invoice')->first();
        $this->assertSame(Word::SOURCE_TOEIC_CORE, $word->source);
        $this->assertSame(1, $word->frequency_rank);
    }
}
