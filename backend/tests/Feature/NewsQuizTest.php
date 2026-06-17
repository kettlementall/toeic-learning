<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NewsQuizTest extends TestCase
{
    use RefreshDatabase;

    private function fakeHttp(): void
    {
        config(['services.anthropic.api_key' => 'test-key']);

        $rss = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel>
  <item>
    <title>Global firms report record quarterly revenue</title>
    <link>https://www.bbc.com/news/business-12345</link>
    <description>A short RSS summary of the article.</description>
    <pubDate>Tue, 17 Jun 2026 08:00:00 GMT</pubDate>
  </item>
</channel></rss>
XML;

        $para = 'Several multinational companies announced strong financial results this week, with executives crediting disciplined cost management and resilient consumer demand across key markets. ';
        $articleHtml = '<html><body><article>'
            . '<p>' . str_repeat($para, 2) . '</p>'
            . '<p>' . str_repeat($para, 2) . '</p>'
            . '</article></body></html>';

        $quizJson = json_encode([
            'level' => 3,
            'suitable' => true,
            'suitability_note' => '難度適中，適合你目前的程度。',
            'summary' => '多家跨國企業本季營收創新高。',
            'questions' => array_map(fn ($i) => [
                'question' => "Comprehension question {$i}?",
                'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
                'correct_answer' => 'A',
                'explanation' => '因為文章第一段提到。',
            ], [1, 2, 3, 4]),
            'vocab' => [
                ['word' => 'revenue', 'part_of_speech' => 'noun', 'definition_zh' => '營收', 'example' => 'Revenue rose sharply.'],
                ['word' => 'resilient', 'part_of_speech' => 'adjective', 'definition_zh' => '有韌性的', 'example' => 'Demand was resilient.'],
                ['word' => 'disciplined', 'part_of_speech' => 'adjective', 'definition_zh' => '有紀律的', 'example' => 'A disciplined approach.'],
                ['word' => 'quarterly', 'part_of_speech' => 'adjective', 'definition_zh' => '每季的', 'example' => 'Quarterly results improved.'],
                ['word' => 'multinational', 'part_of_speech' => 'adjective', 'definition_zh' => '跨國的', 'example' => 'A multinational firm.'],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $reviewJson = json_encode([
            'summary' => '你的閱讀理解表現不錯。',
            'weaknesses' => '推論題可再加強。',
            'review_words' => [],
            'review_grammar' => [],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'feeds.bbci.co.uk/*' => Http::response($rss, 200),
            'www.bbc.com/*' => Http::response($articleHtml, 200),
            'api.anthropic.com/*' => function ($request) use ($quizJson, $reviewJson) {
                // Laravel unicode-escapes Chinese in the request body, so match on
                // an ASCII marker: only the review prompt asks for "weaknesses".
                $body = $request->body();
                $text = str_contains($body, 'weaknesses') ? $reviewJson : $quizJson;

                return Http::response(['content' => [['text' => $text]]], 200);
            },
        ]);
    }

    public function test_news_quiz_full_loop(): void
    {
        $this->fakeHttp();
        $user = User::factory()->create();
        $this->actingAs($user);

        // 1. generate
        $res = $this->post('/quiz', ['type' => 'news_reading', 'topic' => 'top']);
        $res->assertRedirect();

        $quiz = Quiz::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('news_reading', $quiz->type);
        $this->assertSame(4, $quiz->total);
        $this->assertNotEmpty($quiz->article['passage']);
        $this->assertSame(3, $quiz->article['level']);
        $this->assertTrue($quiz->article['suitable']);
        $this->assertCount(4, $quiz->questions);
        $this->assertNull($quiz->questions->first()->word);

        // 2. extracted vocab joined the library (source=news, due today) + cache
        $this->assertDatabaseHas('user_words', [
            'user_id' => $user->id, 'word' => 'revenue', 'source' => 'news',
        ]);
        $revenue = UserWord::where('user_id', $user->id)->where('word', 'revenue')->first();
        $this->assertTrue($revenue->next_review_at->isToday());
        $this->assertTrue(Word::where('word', 'resilient')->exists());
        $this->assertSame(5, UserWord::where('user_id', $user->id)->where('source', 'news')->count());

        // 3. submit -> graded & completed; comprehension questions write no review_logs
        $answers = [];
        foreach ($quiz->questions as $i => $q) {
            $answers[$q->id] = $i === 0 ? 'A' : 'B'; // first correct, rest wrong
        }
        $this->post("/quiz/{$quiz->id}/submit", ['answers' => $answers])
            ->assertRedirect(route('quiz.review', $quiz));

        $quiz->refresh();
        $this->assertSame('completed', $quiz->status);
        $this->assertSame(1, $quiz->score);
        $this->assertSame(0, ReviewLog::where('user_id', $user->id)->count());

        // 4. review -> AI feedback persisted
        $this->get(route('quiz.review', $quiz))->assertOk()->assertSee('你的閱讀理解表現不錯。');
        $quiz->refresh();
        $this->assertNotEmpty($quiz->ai_review['summary']);
    }

    public function test_news_quiz_requires_api_key(): void
    {
        config(['services.anthropic.api_key' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/quiz', ['type' => 'news_reading', 'topic' => 'top'])
            ->assertRedirect();

        $this->assertSame(0, Quiz::count());
    }
}
