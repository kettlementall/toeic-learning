<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\User;
use App\Services\ClaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spend controls: each purpose runs on its configured model, every call is
 * recorded in ai_usages, and the results page makes no AI call on its own.
 */
class AiCostControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.model' => 'claude-opus-4-8',
            'services.anthropic.tasks.quiz' => ['model' => 'claude-sonnet-5-5', 'effort' => 'medium'],
        ]);
    }

    private function completedQuiz(User $user): Quiz
    {
        $quiz = Quiz::create([
            'user_id' => $user->id, 'title' => 'Vocab', 'type' => 'vocab_mc', 'scope' => 'mixed',
            'status' => 'completed', 'score' => 0, 'total' => 1, 'completed_at' => now(),
        ]);
        $quiz->questions()->create([
            'word' => 'budget', 'question' => 'The ____ was approved.', 'options' => ['budget', 'b', 'c', 'd'],
            'correct_answer' => 'A', 'user_answer' => 'B', 'is_correct' => false,
        ]);

        return $quiz;
    }

    public function test_the_results_page_makes_no_ai_call(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $quiz = $this->completedQuiz($user);

        $this->actingAs($user)->get(route('quiz.review', $quiz))
            ->assertOk()
            ->assertSee('Missed 1 of 1')
            ->assertSee('Get AI analysis');

        Http::assertNothingSent();
        $this->assertEmpty($quiz->fresh()->ai_review);
    }

    public function test_quiz_generation_uses_its_own_model_and_is_recorded(): void
    {
        // a thinking model answers with a thinking block before the text
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
                ['type' => 'text', 'text' => json_encode([[
                    'word' => 'budget', 'question' => 'The ____ was approved.',
                    'options' => ['budget', 'invoice', 'venue', 'merger'], 'answer' => 'budget', 'explanation' => '解析',
                ]])],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 400, 'output_tokens' => 1200],
        ], 200)]);

        $questions = app(ClaudeService::class)->generateQuiz(['budget'], 'vocab_mc');

        $this->assertSame('budget', $questions[0]['word']);
        Http::assertSent(fn (Request $r) => $r['model'] === 'claude-sonnet-5-5'
            && $r['output_config'] === ['effort' => 'medium']);

        $row = DB::table('ai_usages')->first();
        $this->assertSame('quiz', $row->purpose);
        $this->assertSame('claude-sonnet-5-5', $row->model);
        $this->assertSame(400, (int) $row->input_tokens);
        $this->assertSame(1200, (int) $row->output_tokens);
    }

    public function test_unlisted_purposes_keep_the_global_model(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"budget": "Plan the money before you spend it."}']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ], 200)]);

        app(ClaudeService::class)->generateExamples(['budget']);

        Http::assertSent(fn (Request $r) => $r['model'] === 'claude-opus-4-8' && ! isset($r['output_config']));
        $this->assertSame('examples', DB::table('ai_usages')->value('purpose'));
    }

    public function test_generation_prompts_do_not_ask_for_explanations(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '[]']]], 200)]);

        $claude = app(ClaudeService::class);
        foreach (['vocab_mc', 'fill_blank', 'part5_grammar'] as $type) {
            $claude->generateQuiz(['budget'], $type);
        }

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => ! str_contains($r['messages'][0]['content'], 'explanation'));
    }

    public function test_a_question_is_explained_on_request_once(): void
    {
        config(['services.anthropic.tasks.quiz_explain' => ['model' => 'claude-sonnet-5-5', 'effort' => 'low']]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"explanation": "budget 是預算。你選的 b 不合語意。"}']],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 80],
        ], 200)]);
        $user = User::factory()->create();
        $quiz = $this->completedQuiz($user);
        $question = $quiz->questions()->first();

        $this->actingAs($user)->get(route('quiz.review', $quiz))->assertSee('AI 解說');

        $url = route('quiz.explain', [$quiz, $question]);
        $this->postJson($url)->assertOk()->assertJson(['explanation' => 'budget 是預算。你選的 b 不合語意。']);
        $this->postJson($url)->assertOk(); // second request is served from the saved text

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['model'] === 'claude-sonnet-5-5'
            && str_contains($r['messages'][0]['content'], '學生選了 (B)，答錯了'));
        $this->assertSame('budget 是預算。你選的 b 不合語意。', $question->fresh()->explanation);
        $this->assertSame('quiz_explain', DB::table('ai_usages')->value('purpose'));
    }

    public function test_another_learners_question_cannot_be_explained(): void
    {
        Http::fake();
        $quiz = $this->completedQuiz(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->postJson(route('quiz.explain', [$quiz, $quiz->questions()->first()]))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_ai_usage_command_reports_cost(): void
    {
        DB::table('ai_usages')->insert([
            'purpose' => 'quiz', 'model' => 'claude-sonnet-5-5',
            'input_tokens' => 1_000_000, 'output_tokens' => 1_000_000, 'created_at' => now(),
        ]);

        $this->artisan('ai:usage')
            ->expectsOutputToContain('Estimated total: $12.000')
            ->assertSuccessful();
    }
}
