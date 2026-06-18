<?php

namespace App\Http\Controllers;

use App\Models\Quiz;
use App\Models\ReviewLog;
use App\Models\UserWord;
use App\Models\Word;
use App\Services\ClaudeService;
use App\Services\DictionaryService;
use App\Services\NewsService;
use App\Services\QuizBuilderService;
use App\Services\SpacedRepetitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class QuizController extends Controller
{
    public function __construct(
        private ClaudeService $claude,
        private QuizBuilderService $builder,
        private SpacedRepetitionService $srs,
        private DictionaryService $dictionary,
        private NewsService $news,
    ) {
    }

    public function create()
    {
        return view('quiz.create', [
            'hasKey' => $this->claude->hasKey(),
            'grammarPoints' => QuizBuilderService::GRAMMAR_POINTS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:vocab_mc,part5_grammar,fill_blank,news_reading',
            'mode' => 'required_unless:type,news_reading|string',
            'count' => 'required_unless:type,news_reading|integer|min:1|max:20',
            'category' => 'nullable|string|max:50',
            'level' => 'nullable|integer|min:1|max:5',
            'points' => 'nullable|array',
            'topic' => 'nullable|string|max:30',
        ]);

        if (! $this->claude->hasKey()) {
            return back()->with('error', 'ANTHROPIC_API_KEY is not set, so AI quiz generation is unavailable. Add your key to backend/.env and restart.');
        }

        if ($data['type'] === 'news_reading') {
            return $this->storeNews($data['topic'] ?? 'top');
        }

        $count = (int) $data['count'];
        $type = $data['type'];

        if ($type === 'part5_grammar') {
            $points = $this->builder->selectGrammarPoints($count, $data['mode'] === 'specific' ? 'specific' : 'adaptive', [
                'points' => $data['points'] ?? [],
            ]);
            $generated = $this->claude->generateQuiz($points, 'part5_grammar');
        } else {
            $words = $this->builder->selectWords($count, $data['mode'], [
                'category' => $data['category'] ?? null,
                'level' => $data['level'] ?? null,
                'scope' => $request->input('scope', 'mixed'),
            ]);
            $generated = $this->claude->generateQuiz($words, $type);
        }

        if (empty($generated)) {
            return back()->with('error', 'AI quiz generation failed. Please try again later (the key may be invalid or there may be a network issue).');
        }

        $quiz = Quiz::create([
            'user_id' => auth()->id(),
            'title' => $this->title($type) . ' · ' . now()->format('m/d H:i'),
            'type' => $type,
            'scope' => $request->input('scope', 'mixed'),
            'status' => 'pending',
            'total' => count($generated),
        ]);

        foreach ($generated as $g) {
            if (empty($g['question']) || empty($g['options'])) {
                continue;
            }
            [$options, $correct] = $this->shuffleOptions(
                array_values($g['options']),
                strtoupper((string) ($g['correct_answer'] ?? 'A')),
            );
            $quiz->questions()->create([
                'word' => $g['word'] ?? null,
                'grammar_point' => $g['grammar_point'] ?? null,
                'question' => $g['question'],
                'options' => $options,
                'correct_answer' => $correct,
                'explanation' => $g['explanation'] ?? null,
            ]);

            // auto-add new vocab words to the library
            if (! empty($g['word'])) {
                $this->ensureInLibrary($g['word']);
            }
        }

        // fix total in case some were skipped
        $quiz->update(['total' => $quiz->questions()->count()]);

        return redirect()->route('quiz.show', $quiz);
    }

    /**
     * Build a BBC news-reading quiz: fetch a recent article, let the AI judge
     * its difficulty for this learner, generate comprehension questions, and
     * add the AI-extracted vocab to the learner's library.
     */
    private function storeNews(string $topic)
    {
        if (! array_key_exists($topic, $this->news->feeds())) {
            $topic = 'top';
        }

        // avoid re-serving articles this user already read recently
        $recentUrls = Quiz::where('user_id', auth()->id())
            ->where('type', 'news_reading')
            ->orderByDesc('id')
            ->limit(20)
            ->pluck('article')
            ->map(fn ($a) => is_array($a) ? ($a['url'] ?? null) : null)
            ->filter()
            ->all();

        $article = $this->news->pickArticle($topic, $recentUrls);
        if (! $article) {
            return back()->with('error', 'Could not fetch a suitable BBC article right now. Please try again or pick another topic.');
        }

        $generated = $this->claude->generateNewsQuiz(
            $article['title'],
            $article['passage'],
            $this->learnerProfile(),
        );

        $questions = $generated['questions'] ?? [];
        if (empty($questions)) {
            return back()->with('error', 'AI failed to generate questions for this article. Please try again.');
        }

        $quiz = Quiz::create([
            'user_id' => auth()->id(),
            'title' => 'News Reading · ' . \Illuminate\Support\Str::limit($article['title'], 60) . ' · ' . now()->format('m/d H:i'),
            'type' => 'news_reading',
            'scope' => 'news',
            'status' => 'pending',
            'total' => count($questions),
            'article' => [
                'source' => 'BBC',
                'topic' => $topic,
                'title' => $article['title'],
                'url' => $article['url'],
                'published_at' => $article['published_at'] ?? null,
                'passage' => $article['passage'],
                'level' => $generated['level'] ?? null,
                'suitable' => $generated['suitable'] ?? null,
                'suitability_note' => $generated['suitability_note'] ?? null,
                'summary' => $generated['summary'] ?? null,
                'vocab' => array_values(array_filter(array_map(
                    fn ($v) => is_array($v) && ! empty($v['word']) ? trim(strtolower($v['word'])) : null,
                    $generated['vocab'] ?? []
                ))),
            ],
        ]);

        foreach ($questions as $g) {
            if (empty($g['question']) || empty($g['options'])) {
                continue;
            }
            [$options, $correct] = $this->shuffleOptions(
                array_values($g['options']),
                strtoupper((string) ($g['correct_answer'] ?? 'A')),
            );
            $quiz->questions()->create([
                'question' => $g['question'],
                'options' => $options,
                'correct_answer' => $correct,
                'explanation' => $g['explanation'] ?? null,
            ]);
        }
        $quiz->update(['total' => $quiz->questions()->count()]);

        $this->addNewsVocab($generated['vocab'] ?? []);

        return redirect()->route('quiz.show', $quiz);
    }

    public function show(Quiz $quiz)
    {
        $this->authorizeOwner($quiz);

        if ($quiz->status === 'completed') {
            return redirect()->route('quiz.review', $quiz);
        }

        $quiz->load('questions');

        return view('quiz.take', compact('quiz'));
    }

    public function submit(Request $request, Quiz $quiz)
    {
        $this->authorizeOwner($quiz);

        $answers = $request->input('answers', []); // [question_id => 'A']
        $quiz->load('questions');

        $score = 0;
        foreach ($quiz->questions as $q) {
            $ans = strtoupper((string) ($answers[$q->id] ?? ''));
            $correct = $ans !== '' && $ans === $q->correct_answer;
            if ($correct) {
                $score++;
            }
            $q->update(['user_answer' => $ans ?: null, 'is_correct' => $correct]);

            if ($q->word) {
                // vocab question -> drive SM-2 (also writes the review_log)
                if ($correct) {
                    // correct multiple-choice -> quality 5 so ease_factor actually
                    // rises (at quality 4 the SM-2 ease delta is exactly 0, which
                    // kept answered-right weak words stuck at high selection weight)
                    $this->srs->gradeByWord($q->word, 5);
                } else {
                    $this->srs->demote($q->word, 'quiz_weak');
                }
            } elseif ($q->grammar_point) {
                // grammar (Part 5) question -> log grammar_point + quality for adaptive stats
                $this->logQuestion($quiz, $q, $correct);
            }
            // news-reading comprehension questions have neither word nor
            // grammar_point: they only count toward the score, and would
            // otherwise write null-dimension review_logs that pollute stats.
        }

        $quiz->update([
            'score' => $score,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return redirect()->route('quiz.review', $quiz);
    }

    public function review(Quiz $quiz)
    {
        $this->authorizeOwner($quiz);

        $quiz->load('questions');

        // generate AI review once
        if (empty($quiz->ai_review)) {
            $review = $this->claude->reviewQuiz($quiz);
            $quiz->update(['ai_review' => $review]);

            // Vocab quizzes: feed AI-suggested words back into the review queue,
            // but only real dictionary words — enrich first and skip anything that
            // can't be looked up (function words like "where", typos) so we never
            // queue empty cards. Skip words already in this quiz: they were graded
            // at submit, so re-demoting here would double-count the lapse. Grammar
            // quizzes don't touch the vocab library; they adapt via boostGrammar().
            if ($quiz->type === 'vocab_mc') {
                $alreadyTested = $quiz->questions
                    ->pluck('word')
                    ->filter()
                    ->map(fn ($w) => strtolower(trim($w)))
                    ->all();
                foreach (($review['review_words'] ?? []) as $w) {
                    if (! is_string($w) || trim($w) === '') {
                        continue;
                    }
                    if (in_array(strtolower(trim($w)), $alreadyTested, true)) {
                        continue; // already graded at submit — don't double-count
                    }
                    $entry = $this->dictionary->lookup($w);
                    if ($entry && $entry->definition_en) {
                        $this->srs->demote($entry->word, 'ai_review');
                    }
                }
            }

            // feed AI-suggested weak grammar points back into the adaptive engine
            $this->boostGrammar($quiz, $review['review_grammar'] ?? []);
        }

        return view('quiz.review', compact('quiz'));
    }

    /**
     * Make AI-recommended weak grammar points get drilled harder in upcoming
     * adaptive Part 5 quizzes: record a weak review_log per point (mirrors the
     * vocab demote loop). Naturally decays as the point is answered correctly.
     */
    private function boostGrammar(Quiz $quiz, array $points): void
    {
        foreach ($points as $p) {
            $code = $this->normalizeGrammarPoint((string) $p);
            if ($code) {
                ReviewLog::create([
                    'user_id' => $quiz->user_id,
                    'quiz_id' => $quiz->id,
                    'grammar_point' => $code,
                    'quality' => 1, // counts as a weakness signal
                    'reviewed_at' => now(),
                ]);
            }
        }
    }

    /**
     * Map an AI grammar suggestion (code or Chinese label) to a valid code.
     */
    private function normalizeGrammarPoint(string $raw): ?string
    {
        $raw = trim($raw);
        if (in_array($raw, QuizBuilderService::GRAMMAR_POINTS, true)) {
            return $raw;
        }

        $labels = [
            '詞性變化' => 'word_form', '詞性' => 'word_form',
            '時態與語態' => 'tense_voice', '時態' => 'tense_voice', '語態' => 'tense_voice',
            '介系詞' => 'preposition', '連接詞' => 'conjunction',
            '代名詞' => 'pronoun', '關係詞' => 'relative', '關係代名詞' => 'relative',
            '比較級' => 'comparison', '主謂一致' => 'agreement',
            '慣用搭配' => 'collocation', '語境詞彙' => 'vocab_in_context',
        ];
        foreach ($labels as $label => $code) {
            if (mb_strpos($raw, $label) !== false) {
                return $code;
            }
        }

        return null;
    }

    // ---------- helpers ----------

    private function logQuestion(Quiz $quiz, $q, bool $correct): void
    {
        $dict = $q->word ? Word::where('word', strtolower($q->word))->first() : null;

        ReviewLog::create([
            'user_id' => $quiz->user_id,
            'quiz_id' => $quiz->id,
            'category' => $dict?->category,
            'part_of_speech' => $dict?->part_of_speech,
            'grammar_point' => $q->grammar_point,
            'quality' => $correct ? 5 : 1,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * A compact snapshot of the learner used to size article difficulty:
     * library size + recent quiz accuracy (percent, null if no completed quizzes).
     */
    private function learnerProfile(): array
    {
        $vocabCount = UserWord::forUser()->count();

        $recent = Quiz::where('user_id', auth()->id())
            ->where('status', 'completed')
            ->where('total', '>', 0)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['score', 'total']);

        $accuracy = null;
        if ($recent->isNotEmpty()) {
            $accuracy = (int) round(
                $recent->sum('score') / max(1, $recent->sum('total')) * 100
            );
        }

        return ['vocab_count' => $vocabCount, 'accuracy' => $accuracy];
    }

    /**
     * Persist AI-extracted article words into the shared words cache and add
     * them to the learner's library (source=news, due today). Mirrors
     * QuizBuilderService::newWords() + ensureInLibrary().
     *
     * @param  array  $vocab  list of ['word','part_of_speech','definition_zh','example']
     */
    private function addNewsVocab(array $vocab): void
    {
        foreach ($vocab as $v) {
            if (! is_array($v) || empty($v['word'])) {
                continue;
            }
            $word = trim(strtolower($v['word']));
            if ($word === '' || ! preg_match('/^[a-z][a-z\- ]*$/', $word)) {
                continue; // skip junk / non-words
            }

            // upsert into the shared cache, but never clobber a richer existing
            // entry (e.g. a seed word) — only fill blanks and keep its source.
            $dict = Word::firstOrNew(['word' => $word]);
            if (! $dict->exists) {
                $dict->source = 'api';
            }
            $dict->part_of_speech ??= $v['part_of_speech'] ?? null;
            $dict->definition_zh ??= $v['definition_zh'] ?? null;
            $dict->example ??= $v['example'] ?? null;
            $dict->save();

            if (UserWord::forUser()->where('word', $word)->exists()) {
                continue;
            }
            UserWord::create([
                'user_id' => auth()->id(),
                'word_id' => $dict->id,
                'word' => $word,
                'source' => 'news',
                'next_review_at' => Carbon::today(),
            ]);
        }
    }

    private function ensureInLibrary(string $word): void
    {
        $word = trim(strtolower($word));
        if ($word === '' || UserWord::forUser()->where('word', $word)->exists()) {
            return;
        }
        $dict = Word::where('word', $word)->first();
        UserWord::create([
            'user_id' => auth()->id(),
            'word_id' => $dict?->id,
            'word' => $word,
            'source' => 'quiz_new',
            'next_review_at' => Carbon::today(),
        ]);
    }

    /** Block access to another user's quiz. */
    private function authorizeOwner(Quiz $quiz): void
    {
        abort_if($quiz->user_id !== auth()->id(), 403);
    }

    private function title(string $type): string
    {
        return match ($type) {
            'part5_grammar' => 'Part 5 Grammar Quiz',
            'fill_blank' => 'Sentence Fill-in-the-Blank Quiz',
            'news_reading' => 'News Reading Quiz',
            default => 'Vocabulary Multiple Choice Quiz',
        };
    }

    /**
     * Shuffle a question's options and remap the correct-answer letter, so the
     * correct choice isn't biased toward a fixed position (LLMs tend to put it
     * at "A"). Prompts are told to explain by option content, not letter, so
     * explanations stay valid after the shuffle.
     *
     * @param  string[]  $options  ordered option strings
     * @param  string    $correct  original correct letter (A–D)
     * @return array{0: string[], 1: string}  [shuffled options, new correct letter]
     */
    private function shuffleOptions(array $options, string $correct): array
    {
        $options = array_values($options);
        $idx = ord($correct) - 65; // 'A' => 0
        $correctText = $options[$idx] ?? ($options[0] ?? null);

        shuffle($options);

        $newIdx = array_search($correctText, $options, true);

        return [$options, chr(65 + ($newIdx === false ? 0 : $newIdx))];
    }
}
