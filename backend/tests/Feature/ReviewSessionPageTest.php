<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The flashcard page offers the sentence as a second chance before the answer,
 * and grading after it is capped at Hard.
 */
class ReviewSessionPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_card_offers_the_sentence_step(): void
    {
        $user = User::factory()->create();
        $word = Word::create(['word' => 'lease', 'definition_en' => 'a rental contract', 'example' => 'We signed a two-year lease.']);
        UserWord::create(['user_id' => $user->id, 'word_id' => $word->id, 'word' => 'lease', 'source' => 'search', 'next_review_at' => now()->subDay()]);

        $this->actingAs($user)->get('/review')
            ->assertOk()
            ->assertSee('Show in a Sentence')
            ->assertSee('Got it from the sentence')
            ->assertSee('We signed a two-year lease.', false);
    }
}
