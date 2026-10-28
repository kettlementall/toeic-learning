<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI-review word suggestions are new intake whenever the learner does not own
 * the word, and new intake comes from the curated list only.
 */
class AiReviewIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unowned_suggestions_outside_the_core_list_are_dropped(): void
    {
        config(['services.anthropic.api_key' => 'test-key']);
        $user = User::factory()->create();

        Word::create(['word' => 'revenue', 'source' => Word::SOURCE_TOEIC_CORE, 'frequency_rank' => 1, 'definition_en' => 'income']);
        Word::create(['word' => 'contract', 'source' => 'api', 'definition_en' => 'an agreement']);
        Word::create(['word' => 'verdict', 'source' => 'api', 'definition_en' => 'a jury decision']);
        UserWord::create([
            'user_id' => $user->id, 'word' => 'contract', 'source' => 'search',
            'interval_days' => 10, 'repetitions' => 3,
            'next_review_at' => now()->addDays(5), 'last_reviewed_at' => now()->subDays(5),
        ]);

        $quiz = Quiz::create([
            'user_id' => $user->id, 'title' => 'Vocab', 'type' => 'vocab_mc', 'scope' => 'mixed',
            'status' => 'completed', 'score' => 0, 'total' => 1, 'completed_at' => now(),
        ]);
        $quiz->questions()->create([
            'word' => 'budget', 'question' => 'The ____ was approved.', 'options' => ['budget', 'b', 'c', 'd'],
            'correct_answer' => 'A', 'user_answer' => 'B', 'is_correct' => false,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['text' => json_encode([
                'summary' => 's', 'weaknesses' => 'w',
                'review_words' => ['revenue', 'contract', 'verdict'],
                'review_grammar' => [],
            ])]]], 200),
            '*' => Http::response([], 404),
        ]);

        $this->actingAs($user)->get("/quiz/{$quiz->id}/review")->assertOk();

        $owned = UserWord::where('user_id', $user->id)->pluck('source', 'word');
        $this->assertSame('ai_review', $owned['revenue'] ?? null, 'core word joins the library');
        $this->assertTrue(
            UserWord::where('user_id', $user->id)->where('word', 'contract')->first()->next_review_at->lte(now()),
            'an owned word is still pulled forward',
        );
        $this->assertArrayNotHasKey('verdict', $owned->all(), 'off-list intake is dropped');
    }
}
