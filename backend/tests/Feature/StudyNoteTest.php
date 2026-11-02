<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Leeches get a study note — collocations, confusables, word parts — generated
 * once per word and shared, and the review card asks for it.
 */
class StudyNoteTest extends TestCase
{
    use RefreshDatabase;

    private function fakeClaude(): void
    {
        config(['services.anthropic.api_key' => 'test-key']);

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['text' => json_encode([
            'collocations' => ['sign a lease (簽租約)', 'lease agreement (租賃合約)', ''],
            'confusables' => [['word' => 'loan', 'note' => 'loan 是借錢'], ['word' => 'rent']],
            'parts' => '',
        ], JSON_UNESCAPED_UNICODE)]]], 200)]);
    }

    public function test_note_is_generated_once_and_cleaned(): void
    {
        $this->fakeClaude();
        $user = User::factory()->create();
        Word::create(['word' => 'lease', 'definition_en' => 'a rental contract', 'definition_zh' => '租約']);

        $first = $this->actingAs($user)->getJson('/words/study-note?q=Lease')->assertOk()->json('study_note');
        $this->actingAs($user)->getJson('/words/study-note?q=lease')->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(['sign a lease (簽租約)', 'lease agreement (租賃合約)'], $first['collocations']);
        $this->assertCount(1, $first['confusables'], 'a confusable without a note is dropped');
        $this->assertSame($first, Word::where('word', 'lease')->first()->study_note);
    }

    public function test_missing_term_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/words/study-note?q=')->assertStatus(422);
    }

    public function test_review_card_marks_leeches_and_carries_their_note(): void
    {
        $user = User::factory()->create();
        $word = Word::create([
            'word' => 'lease', 'definition_en' => 'a rental contract',
            'study_note' => ['collocations' => ['sign a lease'], 'confusables' => [], 'parts' => ''],
        ]);
        UserWord::create([
            'user_id' => $user->id, 'word_id' => $word->id, 'word' => 'lease', 'source' => 'search',
            'lapses' => 5, 'next_review_at' => now()->subDay(),
        ]);

        $this->actingAs($user)->get('/review')
            ->assertOk()
            ->assertSee('\\u0022leech\\u0022:true', false) // @js escapes the quotes
            ->assertSee('sign a lease')
            ->assertSee(route('words.study-note'), false);
    }
}
