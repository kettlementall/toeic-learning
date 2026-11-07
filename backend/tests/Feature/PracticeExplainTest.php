<?php

namespace Tests\Feature;

use App\Models\PracticeQuestion;
use App\Models\User;
use App\Services\PracticeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PracticeFixtures;
use Tests\TestCase;

/**
 * AI explanations for practice questions are generated on demand, once, and
 * shared by everyone who opens that question.
 */
class PracticeExplainTest extends TestCase
{
    use PracticeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPracticeRoot();
        $importer = new PracticeImporter($this->practiceRoot);
        $importer->import($this->readingSet());
        $importer->import($this->listeningSet());
    }

    protected function tearDown(): void
    {
        $this->tearDownPracticeRoot();
        parent::tearDown();
    }

    public function test_explanation_is_generated_once_and_cached(): void
    {
        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['text' => json_encode(
            ['explanation' => '空格在 be 動詞 staying 後，需要形容詞 fit。'], JSON_UNESCAPED_UNICODE)]]], 200)]);

        $q = PracticeQuestion::where('number', 101)->first();
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a)->postJson("/practice/questions/{$q->id}/explain")->assertOk()
            ->assertJson(['explanation' => '空格在 be 動詞 staying 後，需要形容詞 fit。']);
        $this->actingAs($b)->postJson("/practice/questions/{$q->id}/explain")->assertOk();

        Http::assertSentCount(1);
        $this->assertNotNull($q->fresh()->ai_explanation);
        Http::assertSent(fn ($req) => str_contains($req['messages'][0]['content'], '(A) fit')
            && str_contains($req['messages'][0]['content'], '正確答案：(A)'));
    }

    public function test_listening_questions_send_their_transcript(): void
    {
        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['text' => '{"explanation":"ok"}']]], 200)]);

        $q = PracticeQuestion::where('number', 7)->first();
        $this->actingAs(User::factory()->create())->postJson("/practice/questions/{$q->id}/explain")->assertOk();

        Http::assertSent(fn ($req) => str_contains($req['messages'][0]['content'], 'Where is the bank?'));
    }

    public function test_without_a_key_it_reports_unavailable_and_failures_are_not_cached(): void
    {
        config(['services.anthropic.api_key' => null]);
        $q = PracticeQuestion::where('number', 101)->first();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/practice/questions/{$q->id}/explain")->assertStatus(503);

        config(['services.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response('boom', 500)]);
        $this->actingAs($user)->postJson("/practice/questions/{$q->id}/explain")->assertStatus(502);
        $this->assertNull($q->fresh()->ai_explanation);
    }
}
