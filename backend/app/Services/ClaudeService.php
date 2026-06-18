<?php

namespace App\Services;

use App\Models\Quiz;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeService
{
    public function hasKey(): bool
    {
        return ! empty(config('services.anthropic.api_key'));
    }

    /**
     * Supplement dictionary data with Chinese translation + TOEIC usage notes.
     */
    public function enrich(string $word, array $dictData): array
    {
        if (! $this->hasKey()) {
            return [];
        }

        // translate every part of speech, keeping order so the caller can zip
        // the Chinese back onto each meaning.
        $meanings = $dictData['meanings'] ?? [];
        if (empty($meanings)) {
            $meanings = [[
                'pos' => $dictData['part_of_speech'] ?? '',
                'definition_en' => $dictData['definition_en'] ?? '',
            ]];
        }

        $lines = [];
        foreach ($meanings as $i => $m) {
            $n = $i + 1;
            $pos = $m['pos'] ?? '';
            $en = $m['definition_en'] ?? '';
            $lines[] = "{$n}. ({$pos}) {$en}";
        }
        $list = implode("\n", $lines);

        $prompt = <<<PROMPT
你是多益(TOEIC)英語教學專家。針對單字 "{$word}" 的各詞性英文釋義如下：
{$list}
請「依相同順序、相同數量」為每一條提供簡潔的繁體中文翻譯，並給整體一句多益用法提示。
只回傳 JSON，不要其他文字，格式如下：
{"meanings": [{"definition_zh": "第1條的中文翻譯"}], "toeic_note": "多益常見搭配詞或用法提示(繁體中文，30字內)"}
PROMPT;

        $json = $this->callJson($prompt, 1024);

        return is_array($json) ? $json : [];
    }

    /**
     * Generate brand-new TOEIC words not yet in the user's library.
     * Returns array of ['word'=>, 'definition_zh'=>, 'part_of_speech'=>, 'example'=>, 'category'=>].
     */
    public function generateNewWords(int $n, ?int $level = null, ?string $category = null): array
    {
        if (! $this->hasKey() || $n < 1) {
            return [];
        }

        $levelHint = $level ? "難度約 {$level}/5。" : '';
        $catHint = $category ? "主題類別: {$category}。" : '';

        $prompt = <<<PROMPT
請列出 {$n} 個多益(TOEIC)常考英文單字。{$levelHint}{$catHint}
只回傳 JSON 陣列，每個物件格式如下，不要其他文字：
[{"word":"english", "part_of_speech":"noun/verb/adjective/adverb", "definition_zh":"繁體中文翻譯", "example":"一句英文例句", "category":"business/office/finance/travel/general 等"}]
PROMPT;

        $json = $this->callJson($prompt, 2048);

        return is_array($json) ? $json : [];
    }

    /**
     * Generate a natural, TOEIC-style English example sentence for each word.
     * Used to backfill words that arrived without one (dictionary API had no
     * example, or the word was auto-added from a quiz/AI suggestion).
     *
     * @param  string[]  $words
     * @return array<string,string>  map of lowercased word => example sentence
     */
    public function generateExamples(array $words): array
    {
        $words = array_values(array_filter(array_map(
            fn ($w) => trim((string) $w),
            $words
        )));

        if (! $this->hasKey() || empty($words)) {
            return [];
        }

        $list = implode(', ', $words);

        $prompt = <<<PROMPT
請為以下每個英文單字各造一個適合多益(TOEIC)商務情境、自然、約 8-15 字的英文例句，
例句中必須實際包含該單字本身：
{$list}

只回傳 JSON 物件，鍵為單字、值為對應的英文例句字串，不要其他文字：
{"word":"An example sentence using the word."}
PROMPT;

        // The model intermittently emits malformed JSON (e.g. a comma instead of
        // a colon between key and value), which fails to parse. Retry a few times
        // since each attempt is independent and usually succeeds.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $json = $this->callJson($prompt, 4096);
            if (! is_array($json)) {
                continue;
            }

            // keep only string examples, keyed by lowercased word for matching
            $out = [];
            foreach ($json as $word => $example) {
                if (is_string($word) && is_string($example) && trim($example) !== '') {
                    $out[strtolower(trim($word))] = trim($example);
                }
            }

            if (! empty($out)) {
                return $out;
            }
        }

        return [];
    }

    /**
     * Generate quiz questions.
     *
     * @param  array  $items  For vocab: list of words (strings). For part5: list of grammar_point strings.
     * @param  string  $type  vocab_mc | part5_grammar | fill_blank
     * @return array  list of question arrays
     */
    public function generateQuiz(array $items, string $type): array
    {
        if (! $this->hasKey() || empty($items)) {
            return [];
        }

        $prompt = match ($type) {
            'part5_grammar' => $this->part5Prompt($items),
            'fill_blank' => $this->fillBlankPrompt($items),
            default => $this->vocabPrompt($items),
        };

        $json = $this->callJson($prompt, 4096);

        return is_array($json) ? $json : [];
    }

    /**
     * Read a news article, judge whether its difficulty suits the learner, write
     * ~4 reading-comprehension questions, and extract 5-8 TOEIC-worthy words.
     *
     * @param  array  $profile  ['vocab_count'=>int, 'accuracy'=>?int (percent)]
     * @return array  ['level'=>int, 'suitable'=>bool, 'suitability_note'=>string,
     *                 'summary'=>string, 'questions'=>[..], 'vocab'=>[..]]
     */
    public function generateNewsQuiz(string $title, string $passage, array $profile): array
    {
        if (! $this->hasKey() || trim($passage) === '') {
            return [];
        }

        $vocabCount = (int) ($profile['vocab_count'] ?? 0);
        $accuracy = $profile['accuracy'];
        $accLine = $accuracy === null
            ? '近期測驗正確率：尚無紀錄。'
            : "近期測驗平均正確率：約 {$accuracy}%。";

        $prompt = <<<PROMPT
你是多益(TOEIC)英語閱讀教練。以下是一篇英文新聞，標題為「{$title}」：
---
{$passage}
---

學習者資料：單字庫約有 {$vocabCount} 個字。{$accLine}

請完成下列任務，全部用繁體中文說明（題目與選項本身用英文），只回傳 JSON，不要其他文字：
1. 評估這篇文章對「該學習者」的難度是否適合(1-5 級，1最易5最難)，並說明為何適合或偏難/偏易。
2. 出 4 題針對本文的英文閱讀理解選擇題(4選1)，測驗主旨、細節、推論或字義。
3. 從文章挑出 5-8 個適合該學習者學習的多益實用單字(實詞為主，避免過於簡單或專有名詞)，附詞性、繁中翻譯、以及一句取自或貼近文章情境的英文例句。

JSON 格式如下：
{
  "level": 3,
  "suitable": true,
  "suitability_note": "難度評語(繁體中文，2-3句)",
  "summary": "文章重點摘要(繁體中文，2-3句)",
  "questions": [
    {"question":"英文題目", "options":["A選項","B選項","C選項","D選項"], "correct_answer":"A", "explanation":"繁體中文解析，以選項的內容文字說明，切勿使用 A/B/C/D 等選項代號(選項順序之後會被打亂)"}
  ],
  "vocab": [
    {"word":"english", "part_of_speech":"noun/verb/adjective/adverb", "definition_zh":"繁體中文翻譯", "example":"一句英文例句"}
  ]
}
PROMPT;

        $json = $this->callJson($prompt, 4096);

        return is_array($json) ? $json : [];
    }

    /**
     * Analyze a completed quiz and return overall feedback + weak words.
     * Returns ['summary'=>string, 'weaknesses'=>string, 'review_words'=>[..], 'review_grammar'=>[..]].
     */
    public function reviewQuiz(Quiz $quiz): array
    {
        if (! $this->hasKey()) {
            return [
                'summary' => '尚未設定 ANTHROPIC_API_KEY，無法產生 AI 檢討。',
                'weaknesses' => '',
                'review_words' => [],
                'review_grammar' => [],
            ];
        }

        // News-reading quizzes adapt through the extracted vocab (already added to
        // the library at creation), not the vocab/grammar feedback loop, so they
        // get comprehension-focused feedback and empty review_words/grammar.
        if ($quiz->type === 'news_reading') {
            return $this->reviewNewsQuiz($quiz);
        }

        $lines = [];
        foreach ($quiz->questions as $q) {
            $mark = $q->is_correct ? '✓正確' : '✗錯誤';
            $tag = $q->word ?: ($q->grammar_point ?: '');
            $lines[] = "[{$mark}] 考點:{$tag} | 題目:{$q->question} | 你的答案:{$q->user_answer} | 正解:{$q->correct_answer}";
        }
        $detail = implode("\n", $lines);

        $grammarCodes = implode(', ', QuizBuilderService::GRAMMAR_POINTS);

        // Grammar quizzes adapt through grammar points, not the vocab library, so
        // don't ask for review_words there (the options are function words like
        // "where" that have no vocabulary value).
        $isGrammar = $quiz->type === 'part5_grammar';
        $wordsLine = $isGrammar
            ? '"review_words": [],  // 文法測驗請務必回傳空陣列，不要建議任何單字'
            : '"review_words": ["建議優先複習的單字(只列答錯或相關的英文實詞，不要列介系詞/連接詞/代名詞等功能字)"],';

        $prompt = <<<PROMPT
你是多益(TOEIC)學習教練。以下是學生一份「{$quiz->type}」測驗的作答結果（得分 {$quiz->score}/{$quiz->total}）：
{$detail}

請用繁體中文分析，並只回傳 JSON，格式如下，不要其他文字：
{
  "summary": "整體表現總評(2-3句)",
  "weaknesses": "弱點分析(指出常錯的類型或考點)",
  {$wordsLine}
  "review_grammar": ["建議加強的文法考點，若為文法題才填，且只能用下列代碼：{$grammarCodes}"]
}
PROMPT;

        $json = $this->callJson($prompt, 2048);

        return is_array($json) ? $json : [
            'summary' => 'AI 檢討產生失敗，請稍後再試。',
            'weaknesses' => '',
            'review_words' => [],
            'review_grammar' => [],
        ];
    }

    /**
     * Comprehension-focused feedback for a completed news-reading quiz.
     */
    private function reviewNewsQuiz(Quiz $quiz): array
    {
        $article = $quiz->article ?? [];
        $title = $article['title'] ?? '';
        $summary = $article['summary'] ?? '';

        $lines = [];
        foreach ($quiz->questions as $q) {
            $mark = $q->is_correct ? '✓正確' : '✗錯誤';
            $lines[] = "[{$mark}] 題目:{$q->question} | 你的答案:{$q->user_answer} | 正解:{$q->correct_answer}";
        }
        $detail = implode("\n", $lines);

        $prompt = <<<PROMPT
你是多益(TOEIC)閱讀教練。學生剛完成一篇新聞「{$title}」的閱讀理解測驗（得分 {$quiz->score}/{$quiz->total}）。
文章重點：{$summary}
作答結果：
{$detail}

請用繁體中文針對「閱讀理解能力」給回饋，只回傳 JSON，不要其他文字：
{
  "summary": "整體閱讀表現總評(2-3句)",
  "weaknesses": "弱點分析(主旨/細節/推論/字義何者較弱，及閱讀建議)",
  "review_words": [],
  "review_grammar": []
}
PROMPT;

        $json = $this->callJson($prompt, 2048);

        if (! is_array($json)) {
            return [
                'summary' => 'AI 檢討產生失敗，請稍後再試。',
                'weaknesses' => '',
                'review_words' => [],
                'review_grammar' => [],
            ];
        }

        // The model often ignores the "return []" instruction and emits rich
        // review_words/review_grammar (sometimes as objects). News quizzes close
        // their vocab loop at creation, so force these empty — both to honor the
        // design contract and to keep review.blade's implode() from choking on
        // non-string elements.
        $json['review_words'] = [];
        $json['review_grammar'] = [];

        return $json;
    }

    // ---------- prompt builders ----------

    private function vocabPrompt(array $words): string
    {
        $list = implode(', ', $words);
        $n = count($words);

        return <<<PROMPT
請針對以下英文單字，各出一題多益(TOEIC)風格的「單字選擇題」(4選1)：
{$list}

共 {$n} 題。每題提供一個句子(空格用 ____ 表示)，4 個選項中只有一個語意/詞性正確。
只回傳 JSON 陣列，每題格式如下，不要其他文字：
[{"word":"考的單字", "question":"含 ____ 的英文句子", "options":["A選項","B選項","C選項","D選項"], "correct_answer":"A", "explanation":"繁體中文解析，以選項的內容文字說明為何正解、其他選項為何錯，切勿使用 A/B/C/D 等選項代號(選項順序之後會被打亂)"}]
PROMPT;
    }

    private function part5Prompt(array $grammarPoints): string
    {
        $list = implode(', ', $grammarPoints);
        $n = count($grammarPoints);

        return <<<PROMPT
請出 {$n} 題道地的多益(TOEIC) Part 5「句子填空文法題」(4選1)，每題分別測驗以下文法考點(依序對應)：
{$list}

文法考點代碼意義：word_form詞性變化, tense_voice時態語態, preposition介系詞, conjunction連接詞vs介系詞,
pronoun代名詞, relative關係詞, comparison比較級, agreement主謂一致, collocation慣用搭配, vocab_in_context語境詞彙。
每題提供一個商務情境句子(空格用 ____ 表示)，4 個選項含合理誘答，只有一個正確。
只回傳 JSON 陣列，每題格式如下，不要其他文字：
[{"grammar_point":"對應的考點代碼", "question":"含 ____ 的英文句子", "options":["A","B","C","D"], "correct_answer":"A", "explanation":"繁體中文解析，以選項的內容文字說明文法規則與為何其他選項錯，切勿使用 A/B/C/D 等選項代號(選項順序之後會被打亂)"}]
PROMPT;
    }

    private function fillBlankPrompt(array $words): string
    {
        $list = implode(', ', $words);
        $n = count($words);

        return <<<PROMPT
請針對以下英文單字，各出一題「句子填空題」(4選1)，著重正確詞形變化：
{$list}

共 {$n} 題。只回傳 JSON 陣列，格式如下，不要其他文字：
[{"word":"考的單字", "question":"含 ____ 的英文句子", "options":["A","B","C","D"], "correct_answer":"A", "explanation":"繁體中文解析，以選項的內容文字說明，切勿使用 A/B/C/D 等選項代號(選項順序之後會被打亂)"}]
PROMPT;
    }

    // ---------- HTTP + parsing ----------

    /**
     * Call the Anthropic Messages API and parse a JSON object/array out of the reply.
     */
    private function callJson(string $prompt, int $maxTokens = 2048): mixed
    {
        try {
            $resp = Http::timeout(90)
                ->withHeaders([
                    'x-api-key' => config('services.anthropic.api_key'),
                    'anthropic-version' => config('services.anthropic.version'),
                    'content-type' => 'application/json',
                ])
                ->post(config('services.anthropic.base_url'), [
                    'model' => config('services.anthropic.model'),
                    'max_tokens' => $maxTokens,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('Claude API error: ' . $e->getMessage());
            return null;
        }

        if (! $resp->successful()) {
            Log::error('Claude API non-200: ' . $resp->status() . ' ' . $resp->body());
            return null;
        }

        $text = $resp->json('content.0.text', '');

        return $this->extractJson($text);
    }

    /**
     * Pull the first JSON object/array out of an LLM text response.
     */
    private function extractJson(string $text): mixed
    {
        $text = trim($text);

        // strip ```json ... ``` fences
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // fallback: grab from first { or [ to matching last } or ]
        $start = strcspn($text, '{[');
        if ($start < strlen($text)) {
            $sub = substr($text, $start);
            $lastObj = strrpos($sub, '}');
            $lastArr = strrpos($sub, ']');
            $end = max($lastObj === false ? -1 : $lastObj, $lastArr === false ? -1 : $lastArr);
            if ($end > 0) {
                $candidate = substr($sub, 0, $end + 1);
                $decoded = json_decode($candidate, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }

        Log::warning('Claude JSON parse failed: ' . mb_substr($text, 0, 300));
        return null;
    }
}
