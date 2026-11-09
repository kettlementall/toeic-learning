<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Generated questions are keyed by the answer's text, never by a letter the
 * model may have copied off the prompt example.
 */
class AnswerKeyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-key']);
        $this->user = User::factory()->create();
    }

    private function generate(array $questions)
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['text' => json_encode($questions)]]], 200)]);

        return $this->actingAs($this->user)->post('/quiz', ['type' => 'part5_grammar', 'mode' => 'adaptive', 'count' => count($questions)]);
    }

    private function key($q): string
    {
        return $q->options[ord($q->correct_answer) - 65];
    }

    public function test_the_answer_text_sets_the_key_whatever_letter_comes_with_it(): void
    {
        $this->generate([
            // the old failure: "A" copied from the example, explanation and answer say Despite
            ['grammar_point' => 'conjunction', 'question' => '____ the rain, it went ahead.', 'options' => ['However', 'Because', 'Despite', 'Although'],
                'answer' => 'Despite', 'correct_answer' => 'A', 'explanation' => 'Despite + noun.'],
            ['grammar_point' => 'preposition', 'question' => 'Submit it ____ Friday.', 'options' => ['during', 'by', 'until', 'since'],
                'answer' => ' By. ', 'explanation' => 'by = deadline.'], // case and punctuation forgiven
        ])->assertRedirect();

        $questions = Quiz::firstOrFail()->questions;
        $this->assertSame(['Despite', 'by'], $questions->map(fn ($q) => $this->key($q))->all());
    }

    public function test_a_question_whose_answer_matches_no_option_is_dropped(): void
    {
        $this->generate([
            ['grammar_point' => 'collocation', 'question' => '____ a survey.', 'options' => ['practice', 'exercise', 'conduct', 'perform'],
                'answer' => 'conduct', 'explanation' => 'conduct a survey.'],
            ['grammar_point' => 'collocation', 'question' => '____ an agreement.', 'options' => ['arrive', 'achieve', 'get', 'reach'],
                'answer' => 'make', 'explanation' => '?'],
            ['grammar_point' => 'pronoun', 'question' => 'Give it to ____.', 'options' => ['him', 'him', 'his', 'he'],
                'answer' => 'him', 'explanation' => 'duplicate options'],
        ])->assertRedirect();

        $quiz = Quiz::firstOrFail();
        $this->assertSame(1, $quiz->total);
        $this->assertSame('conduct', $this->key($quiz->questions->first()));
    }

    public function test_no_quiz_is_left_behind_when_every_question_is_dropped(): void
    {
        $this->generate([
            ['grammar_point' => 'collocation', 'question' => '____ an agreement.', 'options' => ['arrive', 'achieve', 'get', 'reach'],
                'answer' => 'make', 'explanation' => '?'],
        ])->assertSessionHas('error');

        $this->assertSame(0, Quiz::count());
    }

    public function test_verify_keys_moves_contradicted_keys_and_regrades(): void
    {
        $quiz = Quiz::create(['user_id' => $this->user->id, 'title' => 'P5', 'type' => 'part5_grammar', 'scope' => 'mixed',
            'status' => 'completed', 'score' => 1, 'total' => 2, 'completed_at' => now()]);
        $wrongKey = $quiz->questions()->create(['grammar_point' => 'conjunction', 'question' => '____ the rain.',
            'options' => ['However', 'Because', 'Despite', 'Although'], 'correct_answer' => 'A',
            'explanation' => 'Despite 為介系詞，故選 Despite。', 'user_answer' => 'C', 'is_correct' => false]);
        $rightKey = $quiz->questions()->create(['grammar_point' => 'collocation', 'question' => '____ an impression.',
            'options' => ['do', 'make', 'give', 'take'], 'correct_answer' => 'B',
            'explanation' => 'make an impression，故選 make。', 'user_answer' => 'B', 'is_correct' => true]);

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['text' => json_encode([
            (string) $wrongKey->id => 'Despite',
            (string) $rightKey->id => 'make',
        ])]]], 200)]);

        $this->artisan('quiz:verify-keys', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('A', $wrongKey->fresh()->correct_answer, 'dry run changes nothing');

        $this->artisan('quiz:verify-keys')->assertSuccessful();

        $this->assertSame('C', $wrongKey->fresh()->correct_answer);
        $this->assertTrue($wrongKey->fresh()->is_correct, 'the learner was right all along');
        $this->assertSame('B', $rightKey->fresh()->correct_answer);
        $this->assertSame(2, $quiz->fresh()->score);
    }
}
