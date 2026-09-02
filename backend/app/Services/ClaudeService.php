<?php

namespace App\Services;

use App\Models\Quiz;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeService
{
    /** Most words sent as a "already learned, do not repeat" list in one prompt. */
    private const EXCLUDE_LIMIT = 1500;

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
請「依相同順序、相同數量」為每一條提供簡潔的繁體中文翻譯，給整體一句多益用法提示，並給一句幫助記憶的小技巧。
只回傳 JSON，不要其他文字，格式如下：
{"meanings": [{"definition_zh": "第1條的中文翻譯"}], "toeic_note": "多益常見搭配詞或用法提示(繁體中文，30字內)", "mnemonic": "記憶小技巧(繁體中文，諧音/字根字首/拆字/聯想擇一，須與字義相關，30字內)"}
PROMPT;

        $json = $this->callJson($prompt, 1024);

        return is_array($json) ? $json : [];
    }

    /**
     * Write a whole dictionary entry from scratch. Used only as a fallback when
     * dictionaryapi.dev is unreachable, so a dictionary outage doesn't take the
     * word search down with it. Unlike enrich(), this also produces the English
     * definitions, so the caller needs no second call.
     *
     * Returns [] when there is no API key, the call fails, or the term isn't a
     * real English word (so a typo during an outage still reports a typo).
     */
    public function defineWord(string $word): array
    {
        if (! $this->hasKey()) {
            return [];
        }

        $prompt = <<<PROMPT
你是多益(TOEIC)英語教學專家兼字典編輯。請提供英文單字 "{$word}" 的字典資料。
如果它不是一個真正的英文單字(例如拼錯或亂打)，只回傳 {"valid": false}，不要勉強解釋。
否則只回傳 JSON，不要其他文字，格式如下：
{"valid": true, "phonetic": "IPA音標，含前後斜線", "meanings": [{"pos": "詞性(noun/verb/adjective/adverb/preposition...)", "definition_en": "簡潔的英文釋義", "definition_zh": "簡潔的繁體中文翻譯", "example": "一句英文例句"}], "toeic_note": "多益常見搭配詞或用法提示(繁體中文，30字內)", "synonyms": "同義字，英文逗號分隔，最多6個", "mnemonic": "記憶小技巧(繁體中文，諧音/字根字首/拆字/聯想擇一，須與字義相關，30字內)"}
meanings 每個詞性一條，依常用程度排序，最多4條。
PROMPT;

        $json = $this->callJson($prompt, 1024);

        if (! is_array($json) || ($json['valid'] ?? false) !== true || empty($json['meanings'])) {
            return [];
        }

        return $json;
    }

    /**
     * Check a batch of words against the TOEIC vocabulary standard.
     *
     * Judging existing words one by one is a far easier task for the model than
     * producing new ones under an exclusion constraint, so this catches the
     * invented compounds and obscure entries that generation lets through.
     *
     * @param  string[]  $words
     * @return array<string,bool>  lowercased word => keep it?
     */
    public function verifyToeicWords(array $words): array
    {
        $words = array_values(array_filter(array_map('trim', $words)));

        if (! $this->hasKey() || empty($words)) {
            return [];
        }

        $list = implode(', ', $words);

        $prompt = <<<PROMPT
以下是一份「多益(TOEIC)高頻字彙表」的候選字，請逐字判斷是否應該保留。

判斷基準：多益是「職場英語溝通」測驗，字彙難度約在 CEFR A2-B2 之間。

保留(true)的條件必須全部符合：
- 是標準、真實存在的英文單字(可在一般英漢辭典查到)。
- 在多益測驗中確實可能出現，屬於商務/辦公室/日常情境的實用字彙。
- 難度不超過 CEFR B2。

判為 false 的情況：
- 拼寫錯誤、或是拼湊出來的假複合詞(例如 outbound-based、restock-able)。
- CEFR C1/C2 等級的進階字彙，或是 GRE/SAT 這類學術測驗才會考的字
  (例如 paucity、penchant、propitious、quintessential、scrupulous、tacit)。
- 過於冷僻、學術或法律專用，多益幾乎不考。
- 專有名詞、縮寫。

寧可嚴格：這份表是給考生分配有限的學習時間用的，放進一個不會考的字，
代價比漏掉一個邊緣字更高。

候選字：
{$list}

只回傳 JSON 物件，鍵為單字、值為 true 或 false，不要其他文字。每個候選字都必須出現：
{"word": true, "another": false}
PROMPT;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $json = $this->callJson($prompt, 4096);
            if (! is_array($json)) {
                continue;
            }

            $out = [];
            foreach ($json as $word => $keep) {
                if (is_string($word) && is_bool($keep)) {
                    $out[strtolower(trim($word))] = $keep;
                }
            }

            if (! empty($out)) {
                return $out;
            }
        }

        return [];
    }

    /**
     * One batch of the curated TOEIC high-frequency list.
     *
     * Unlike generateNewWords(), this asks the model to recite a well-known list
     * in rank order rather than to invent words that avoid an exclusion set. The
     * former is something it has actually memorised; the latter structurally
     * pushes it down the frequency curve toward obscure vocabulary.
     *
     * @param  int  $from  1-based rank this batch should start at
     * @param  string[]  $exclude  words already collected in earlier batches
     * @return array<int,array<string,string>>
     */
    public function generateToeicCoreBatch(int $from, int $size, array $exclude = []): array
    {
        if (! $this->hasKey() || $size < 1) {
            return [];
        }

        $to = $from + $size - 1;

        $excludeHint = '';
        if (! empty($exclude)) {
            $known = array_values(array_unique($exclude));
            if (count($known) > self::EXCLUDE_LIMIT) {
                $known = array_slice($known, -self::EXCLUDE_LIMIT);
            }
            $list = implode(', ', $known);
            $excludeHint = "\n以下單字先前批次已經收錄，不要重複(含其變化形)：\n{$list}\n";
        }

        $prompt = <<<PROMPT
你正在整理一份「多益(TOEIC)高頻核心字彙表」，依實際考試出現頻率由高到低排序。
請列出這份清單的第 {$from} 到第 {$to} 名，共 {$size} 個單字。

要求：
- 必須是多益測驗真正常考的字彙，以商務、辦公室、人事、財務、行銷、物流、旅遊、活動等情境為主。
- 一律使用原形(單數、原型動詞)，不要收錄複數或過去式等變化形。
- 不要收錄專有名詞、縮寫、過於基礎的字(如 the, go, big)或考試罕見的冷僻字。
- 同一個字族只收最常考的那個詞性形式。
{$excludeHint}
只回傳 JSON 陣列，每個物件格式如下，不要其他文字：
[{"word":"english", "part_of_speech":"noun/verb/adjective/adverb", "definition_zh":"繁體中文翻譯", "category":"business/office/finance/marketing/hr/logistics/travel/contracts/technology/general 擇一"}]
PROMPT;

        $json = $this->callJson($prompt, 8192);

        return is_array($json) ? $json : [];
    }

    /**
     * Generate brand-new TOEIC words not yet in the user's library.
     * Returns array of ['word'=>, 'definition_zh'=>, 'part_of_speech'=>, 'example'=>, 'category'=>].
     *
     * @param  string[]  $exclude  words the user already has; without these the
     *                             model keeps returning the same common words
     *                             and every result is discarded as a duplicate.
     */
    public function generateNewWords(int $n, ?int $level = null, ?string $category = null, array $exclude = []): array
    {
        if (! $this->hasKey() || $n < 1) {
            return [];
        }

        $levelHint = $level ? "難度約 {$level}/5。" : '';
        $catHint = $category ? "主題類別: {$category}。" : '';

        // Ask for well over what is needed: even with the exclusion list below,
        // roughly a third of the suggestions come back as words the user already
        // has and get filtered out by the caller. Small requests need a floor,
        // or a couple of duplicates leave the quiz short of new words.
        $ask = min(max($n * 3, 10), $n + 20);

        $excludeHint = '';
        if (! empty($exclude)) {
            $known = array_values(array_unique($exclude));
            // Cap the list so the prompt stays bounded on large libraries. Once
            // the library outgrows the cap, sample randomly rather than always
            // truncating the same head — the excluded subset then rotates
            // between calls instead of permanently ignoring the same words.
            if (count($known) > self::EXCLUDE_LIMIT) {
                shuffle($known);
                $known = array_slice($known, 0, self::EXCLUDE_LIMIT);
            }
            $list = implode(', ', $known);
            $excludeHint = "\n以下單字使用者已經學過，絕對不要出現在結果中(包含其複數、過去式等變化形)：\n{$list}\n";
        }

        $prompt = <<<PROMPT
請列出 {$ask} 個多益(TOEIC)常考英文單字。{$levelHint}{$catHint}
{$excludeHint}
只回傳 JSON 陣列，每個物件格式如下，不要其他文字：
[{"word":"english", "part_of_speech":"noun/verb/adjective/adverb", "definition_zh":"繁體中文翻譯", "example":"一句英文例句", "category":"business/office/finance/travel/general 等", "mnemonic":"記憶小技巧(繁體中文，諧音/字根字首/拆字/聯想擇一，須與字義相關，30字內)"}]
PROMPT;

        $json = $this->callJson($prompt, 4096);

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
     * Generate a Traditional-Chinese memory aid / mnemonic for each word
     * (諧音/字根字首/拆字/聯想擇一). Backs the on-the-fly card button, the
     * Daily Review reveal fallback, and the words:backfill-mnemonics command.
     * Words acquired via lookup/quiz/news get their mnemonic folded into those
     * existing AI calls instead.
     *
     * @param  array<int,array{word:string,definition_zh?:?string}>  $items
     * @return array<string,string>  map of lowercased word => mnemonic
     */
    public function generateMnemonics(array $items): array
    {
        $items = array_values(array_filter($items, fn ($i) => ! empty($i['word'])));

        if (! $this->hasKey() || empty($items)) {
            return [];
        }

        $lines = [];
        foreach ($items as $i) {
            $word = trim((string) $i['word']);
            $zh = trim((string) ($i['definition_zh'] ?? ''));
            $lines[] = $zh !== '' ? "{$word} ({$zh})" : $word;
        }
        $list = implode("\n", $lines);

        $prompt = <<<PROMPT
請為以下每個英文單字各給「一句繁體中文記憶小技巧」，幫助學習者記住該單字，
方法可用諧音、字根字首、拆字或聯想擇一，須與字義相關，每句約 30 字內：
{$list}

只回傳 JSON 物件，鍵為單字、值為對應的記憶小技巧字串，不要其他文字：
{"word":"記憶小技巧"}
PROMPT;

        // Same malformed-JSON retry strategy as generateExamples().
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $json = $this->callJson($prompt, 4096);
            if (! is_array($json)) {
                continue;
            }

            $out = [];
            foreach ($json as $word => $mnemonic) {
                if (is_string($word) && is_string($mnemonic) && trim($mnemonic) !== '') {
                    $out[strtolower(trim($word))] = trim($mnemonic);
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
            ? '近期測驗表現：尚無紀錄。'
            : "近期測驗表現參考值：約 {$accuracy}%（僅供你內部評估難度，切勿在任何回覆文字中提及此數字）。";

        $prompt = <<<PROMPT
你是多益(TOEIC)英語閱讀教練。以下是一篇英文新聞，標題為「{$title}」：
---
{$passage}
---

學習者資料：單字庫約有 {$vocabCount} 個字。{$accLine}

請完成下列任務，全部用繁體中文說明（題目與選項本身用英文），只回傳 JSON，不要其他文字：
1. 評估這篇文章對「該學習者」的難度是否適合(1-5 級，1最易5最難)，並說明為何適合或偏難/偏易（評語請以文章本身的字彙、句構難度為依據，不要提及任何正確率百分比或測驗分數）。
2. 出 4 題針對本文的英文閱讀理解選擇題(4選1)，測驗主旨、細節、推論或字義。
3. 從文章挑出 5-8 個適合該學習者學習的多益實用單字(實詞為主，避免過於簡單或專有名詞)，附詞性、繁中翻譯、一句取自或貼近文章情境的英文例句，以及一句繁體中文記憶小技巧。

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
    {"word":"english", "part_of_speech":"noun/verb/adjective/adverb", "definition_zh":"繁體中文翻譯", "example":"一句英文例句", "mnemonic":"記憶小技巧(繁體中文，諧音/字根字首/拆字/聯想擇一，須與字義相關，30字內)"}
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

共 {$n} 題。每題提供一個句子(空格用 ____ 表示)，測驗的是「字彙意思」而非文法：
- 4 個選項必須是「四個不同的英文單字」，詞性相同、難度相近，只有一個符合句子語意。
- 誘答選項須為意思不同的其他真實單字，不要用同一個字的不同詞形(如 -tion/-ing/-ly/-ed)當選項，以免變成詞性/文法題。
只回傳 JSON 陣列，每題格式如下，不要其他文字：
[{"word":"考的單字", "question":"含 ____ 的英文句子", "options":["A選項","B選項","C選項","D選項"], "correct_answer":"A", "explanation":"繁體中文解析，說明正解單字為何符合語意、其他三個單字語意為何不合，切勿使用 A/B/C/D 等選項代號(選項順序之後會被打亂)"}]
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
