<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\ReviewLog;
use App\Models\UserWord;
use App\Models\Word;
use App\Support\Lemma;
use Illuminate\Support\Facades\DB;

class QuizBuilderService
{
    public const GRAMMAR_POINTS = [
        'word_form', 'tense_voice', 'preposition', 'conjunction', 'pronoun',
        'relative', 'comparison', 'agreement', 'collocation', 'vocab_in_context',
    ];

    public function __construct(
        private ClaudeService $claude,
        private SpacedRepetitionService $srs,
    ) {
    }

    /**
     * Select words for a vocabulary quiz.
     *
     * @param  string  $mode  smart | weak | custom
     * @param  array   $opts  ['tags'=>?, 'level'=>?, 'category'=>?]
     * @return string[]  list of words
     */
    public function selectWords(int $n, string $mode = 'smart', array $opts = []): array
    {
        if ($mode === 'custom') {
            return $this->customWords($n, $opts);
        }

        // Words a quiz built today is already asking about. Another quiz in the
        // same sitting should not ask again — nothing has been graded yet, so
        // they all still look due. The other half of that rule, words already
        // answered correctly today, lives in UserWord::notClearedToday().
        $waiting = $this->waitingInAnUnfinishedQuiz();

        if ($mode === 'weak') {
            // words the user just looked up go first, capped at half the quiz
            // so the weak-word drill still gets its share
            $fresh = $this->freshSearchWords(intdiv($n, 2), $waiting);

            // the lookups past the cap wait their turn: weakWords() samples the
            // whole library and would otherwise put them back over it
            $held = array_merge($waiting, UserWord::forUser()->freshSearch()->pluck('word')->all());

            return array_merge($fresh, $this->weakWords($n - count($fresh), $held));
        }

        // the smart mix reads its fresh lookups off the due queue instead,
        // which already orders them first — see dueFirstReview()
        return $this->smartMix($n, $opts, $waiting);
    }

    /**
     * Searched words not yet tested, most recently looked up first.
     *
     * @return string[]
     */
    private function freshSearchWords(int $limit, array $exclude = []): array
    {
        if ($limit < 1) {
            return [];
        }

        return UserWord::forUser()
            ->freshSearch()
            ->when($exclude, fn ($q) => $q->whereNotIn('word', $exclude))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('word')
            ->all();
    }

    /**
     * Smart mix: the cards closest to being forgotten, plus a fixed share of
     * new words.
     */
    private function smartMix(int $n, array $opts, array $waiting = []): array
    {
        $reviewN = $n - $this->newWordAllowance($n);

        $review = $this->dueFirstReview($reviewN, intdiv($n, 2), $waiting);

        // top up review portion from any user words if not enough
        if (count($review) < $reviewN) {
            $more = UserWord::forUser()
                ->active()
                ->whereNotIn('word', $review)
                ->inRandomOrder()
                ->limit($reviewN - count($review))
                ->pluck('word')
                ->all();
            $review = array_merge($review, $more);
        }

        // fill the remainder with new words (the allowance above when review is
        // full; more if the library was too small to fill the review portion).
        $newWords = $this->newWords($n - count($review), $opts);

        $all = array_values(array_unique(array_merge($review, $newWords)));

        // final top-up from seed if still short
        if (count($all) < $n) {
            $fill = Word::whereNotIn('word', $all)
                ->inRandomOrder()
                ->limit($n - count($all))
                ->pluck('word')
                ->all();
            $all = array_merge($all, $fill);
        }

        return array_slice($all, 0, $n);
    }

    /**
     * The review portion, due-first.
     *
     * SpacedRepetitionService::dueWords() puts fresh lookups first, then
     * anything missed today, then orders by relative overdueness — so the quiz
     * drills exactly the cards the review page would have shown, and every
     * slot lands on a card quizCorrect() will actually reschedule.
     *
     * Fresh lookups are held to $freshCap so established cards keep a share.
     *
     * Once the ripe queue is spent — a second quiz in one sitting, or a small
     * library already cleared — weakWords() takes over with ordinary practice.
     * Those answers are logged but change no schedule (see quizCorrect): a
     * card that is not ripe has nothing to prove by being asked early.
     *
     * @return string[]
     */
    private function dueFirstReview(int $n, int $freshCap, array $waiting): array
    {
        if ($n < 1) {
            return [];
        }

        $held = $waiting;
        $picked = [];
        $freshUsed = 0;

        // Ask for a window wider than the portion: words held back and lookups
        // past their cap are skipped, and the queue is ordered, so a window of
        // exactly $n would come back short. The daily capacity is the
        // calendar's unit of work, not a ceiling on one sitting.
        $window = $n + count($held) + $freshCap;

        foreach ($this->srs->dueWords(null, $window) as $uw) {
            if (count($picked) >= $n) {
                break;
            }
            if (in_array($uw->word, $held, true)) {
                continue;
            }
            if ($uw->is_fresh_search) {
                if ($freshUsed >= $freshCap) {
                    // over the cap: hold it back from the top-ups below too
                    $held[] = $uw->word;

                    continue;
                }
                $freshUsed++;
            }
            $picked[] = $uw->word;
        }

        if (count($picked) < $n) {
            $picked = array_merge(
                $picked,
                $this->weakWords($n - count($picked), array_merge($picked, $held))
            );
        }

        return $picked;
    }

    /**
     * Words sitting in a quiz built today and never submitted.
     *
     * Building a second quiz before finishing the first would otherwise draw
     * the same words: nothing has been graded yet, so they are all still due.
     * Scoped to today on purpose — a quiz abandoned weeks ago must not keep its
     * words out of circulation for good.
     *
     * @return string[]
     */
    private function waitingInAnUnfinishedQuiz(): array
    {
        return QuizQuestion::whereNotNull('word')
            ->whereIn('quiz_id', Quiz::where('user_id', auth()->id())
                ->where('status', 'pending')
                ->whereDate('created_at', today())
                ->select('id'))
            ->distinct()
            ->pluck('word')
            // quiz_questions keeps whatever case the generator produced, while
            // user_words is always lower-cased: compare on the same footing
            ->map(fn ($word) => trim(strtolower($word)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * How many new words a quiz of $n questions introduces.
     *
     * A flat share with a floor, not a budget. Quizzes are the only study
     * surface, so one that carries no new words means the curated TOEIC list
     * never advances.
     *
     * Note that this is a share of every quiz, so intake scales with how much
     * the learner studies: ten quizzes a day is ten times the new words, and
     * each one costs reviews for years. Nothing downstream absorbs that any
     * more — the queue simply gets longer — so this is the dial that decides
     * how long the tail grows.
     */
    private function newWordAllowance(int $n): int
    {
        $share = (float) config('srs.new_word_share');
        $allowance = (int) floor($n * $share);

        // a quiz big enough to spare a slot always carries something new
        return $n >= 5 ? max(1, $allowance) : $allowance;
    }

    /**
     * Weak / overdue words from the user's library, picked by weighted random
     * sampling (biased toward less-familiar & overdue words, but with variety).
     *
     * Words tested in the last few quizzes are down-weighted (cooldown) so the
     * selection rotates instead of drilling the same cluster of hard words over
     * and over. Cooldown only reweights — it never excludes — so we never run
     * short even if the whole library was recently seen.
     *
     * Words this sitting has already answered correctly are left out, so a long
     * run of quizzes keeps finding material; if that empties the pool the whole
     * library comes back, because running short is worse than repeating.
     */
    private function weakWords(int $n, array $exclude = []): array
    {
        if ($n < 1) {
            return [];
        }

        $pool = fn () => UserWord::forUser()
            ->active()
            ->when($exclude, fn ($q) => $q->whereNotIn('word', $exclude));

        $candidates = $pool()->notClearedToday()->get(['word', 'ease_factor', 'next_review_at']);

        if ($candidates->isEmpty()) {
            $candidates = $pool()->get(['word', 'ease_factor', 'next_review_at']);
        }
        if ($candidates->isEmpty()) {
            return [];
        }

        $cooldown = $this->recentlyTestedPenalties();

        $now = now();
        $weights = [];
        foreach ($candidates as $c) {
            // lower ease_factor (less familiar) -> higher weight
            $w = max(0.2, 2.7 - (float) $c->ease_factor);
            // overdue words get a strong boost
            if ($c->next_review_at && $c->next_review_at <= $now) {
                $w *= 2.5;
            }
            // recently tested -> down-weight so the pool rotates
            $w *= $cooldown[strtolower(trim($c->word))] ?? 1.0;
            $weights[$c->word] = $w;
        }

        return $this->weightedSample($weights, $n);
    }

    /**
     * Build a word => multiplier map penalizing words seen in the most recent
     * quizzes. The more recent the quiz, the harder the penalty; older quizzes
     * decay back toward 1.0 (no penalty).
     *
     * @return array<string,float>
     */
    private function recentlyTestedPenalties(int $lookback = 3): array
    {
        $quizIds = Quiz::where('user_id', auth()->id())
            ->orderByDesc('id')
            ->limit($lookback)
            ->pluck('id');

        if ($quizIds->isEmpty()) {
            return [];
        }

        // index 0 = most recent quiz; absent index -> lightest penalty
        $factors = [0.15, 0.35, 0.6];

        $penalties = [];
        foreach ($quizIds->values() as $i => $id) {
            $factor = $factors[$i] ?? 0.6;
            $words = QuizQuestion::where('quiz_id', $id)
                ->whereNotNull('word')
                ->pluck('word');
            foreach ($words as $word) {
                $word = strtolower(trim($word));
                if ($word === '') {
                    continue;
                }
                // a word in several recent quizzes keeps the strongest penalty
                if (! isset($penalties[$word]) || $factor < $penalties[$word]) {
                    $penalties[$word] = $factor;
                }
            }
        }

        return $penalties;
    }

    /**
     * New words not yet in the user's library: builtin seed first, then AI.
     */
    private function newWords(int $n, array $opts): array
    {
        if ($n < 1) {
            return [];
        }

        $ownedWords = UserWord::forUser()->pluck('word')->all();

        $filters = function ($query) use ($opts, $ownedWords) {
            $query->whereNotIn('word', $ownedWords);
            if (! empty($opts['category'])) {
                $query->where('category', $opts['category']);
            }
            if (! empty($opts['level'])) {
                $query->where('level', $opts['level']);
            }

            return $query;
        };

        // The curated high-frequency list comes first, most commonly tested
        // word first, so study time goes to the words the exam actually uses.
        $fromSeed = $filters(Word::toeicCore())->limit($n)->pluck('word')->all();

        // then anything else already cached locally
        if (count($fromSeed) < $n) {
            $fromSeed = array_merge($fromSeed, $filters(Word::query())
                ->whereNotIn('word', $fromSeed)
                ->where('source', '!=', Word::SOURCE_TOEIC_CORE)
                ->inRandomOrder()
                ->limit($n - count($fromSeed))
                ->pluck('word')
                ->all());
        }

        if (count($fromSeed) >= $n) {
            return $fromSeed;
        }

        // not enough -> ask Claude to generate fresh words. The library is passed
        // along as an exclusion list, otherwise the model returns the same common
        // TOEIC words the user already owns and everything is filtered out below.
        $need = $n - count($fromSeed);
        $generated = $this->claude->generateNewWords(
            $need,
            $opts['level'] ?? null,
            $opts['category'] ?? null,
            array_merge($ownedWords, $fromSeed),
        );

        // Inflected forms of words already owned ("offers" next to "offer") are
        // duplicates dressed up as new material, and exact-match filtering never
        // catches them. Derived forms ("investment" next to "invest") are kept —
        // Part 5 tests word families, so those are worth learning separately.
        $knownStems = Lemma::index(array_merge($ownedWords, $fromSeed));

        $newWords = [];
        foreach ($generated as $g) {
            if (count($newWords) >= $need) {
                break;
            }
            if (empty($g['word'])) {
                continue;
            }
            $word = trim(strtolower($g['word']));
            // the model also repeats itself within a single response
            if (in_array($word, $ownedWords, true)
                || in_array($word, $fromSeed, true)
                || in_array($word, $newWords, true)) {
                continue;
            }
            if (Lemma::isKnown($word, $knownStems)) {
                continue;
            }
            $knownStems += Lemma::index([$word]);
            // persist generated word into the words cache
            Word::updateOrCreate(['word' => $word], [
                'part_of_speech' => $g['part_of_speech'] ?? null,
                'definition_zh' => $g['definition_zh'] ?? null,
                'example' => $g['example'] ?? null,
                'mnemonic' => $g['mnemonic'] ?? null,
                'category' => $g['category'] ?? null,
                'source' => 'api',
                'level' => $opts['level'] ?? 3,
            ]);
            $newWords[] = $word;
        }

        return array_merge($fromSeed, $newWords);
    }

    private function customWords(int $n, array $opts): array
    {
        $scope = $opts['scope'] ?? 'mixed';

        $query = match ($scope) {
            'custom' => UserWord::forUser(),
            'builtin' => Word::where('source', 'seed'),
            default => Word::query(),
        };

        if ($scope === 'custom' && ! empty($opts['tags'])) {
            $query->where('tags', 'like', '%' . $opts['tags'] . '%');
        }
        if ($scope !== 'custom') {
            if (! empty($opts['category'])) {
                $query->where('category', $opts['category']);
            }
            if (! empty($opts['level'])) {
                $query->where('level', $opts['level']);
            }
        }

        return $query->inRandomOrder()->limit($n)->pluck('word')->all();
    }

    /**
     * Pick grammar points for a Part 5 quiz, weighted by error rate (adaptive).
     *
     * @param  string  $mode  adaptive | specific
     * @param  array   $opts  ['points'=>[...]] for specific mode
     * @return string[]  list of grammar point codes, length $n
     */
    public function selectGrammarPoints(int $n, string $mode = 'adaptive', array $opts = []): array
    {
        if ($mode === 'specific' && ! empty($opts['points'])) {
            $points = array_values(array_intersect($opts['points'], self::GRAMMAR_POINTS));
            return $this->fillToN($points, $n);
        }

        // adaptive: weight = error rate per grammar point (default weight for unseen)
        $stats = ReviewLog::where('user_id', auth()->id())
            ->whereNotNull('grammar_point')
            ->select('grammar_point', DB::raw('COUNT(*) as total'), DB::raw('SUM(CASE WHEN quality < 3 THEN 1 ELSE 0 END) as wrong'))
            ->groupBy('grammar_point')
            ->get()
            ->keyBy('grammar_point');

        $weights = [];
        foreach (self::GRAMMAR_POINTS as $gp) {
            $s = $stats->get($gp);
            if (! $s || $s->total == 0) {
                $weights[$gp] = 1.0; // unseen -> baseline
            } else {
                // error rate, floored so every point keeps some chance
                $weights[$gp] = 0.2 + ($s->wrong / $s->total) * 2.0;
            }
        }

        // weighted random pick (with replacement) of n points
        $picks = [];
        for ($i = 0; $i < $n; $i++) {
            $picks[] = $this->weightedPick($weights);
        }

        return $picks;
    }

    /**
     * Weighted sampling WITHOUT replacement: pick up to $n distinct keys,
     * each chosen with probability proportional to its weight.
     *
     * @param  array<string,float>  $weights
     * @return string[]
     */
    private function weightedSample(array $weights, int $n): array
    {
        $picked = [];
        while (count($picked) < $n && ! empty($weights)) {
            $key = $this->weightedPick($weights);
            $picked[] = $key;
            unset($weights[$key]);
        }

        return $picked;
    }

    private function weightedPick(array $weights): string
    {
        $total = array_sum($weights);
        $r = mt_rand() / mt_getrandmax() * $total;
        $acc = 0;
        foreach ($weights as $key => $w) {
            $acc += $w;
            if ($r <= $acc) {
                return $key;
            }
        }
        return array_key_first($weights);
    }

    private function fillToN(array $items, int $n): array
    {
        if (empty($items)) {
            return array_slice(self::GRAMMAR_POINTS, 0, $n);
        }
        $out = [];
        while (count($out) < $n) {
            foreach ($items as $it) {
                if (count($out) >= $n) {
                    break;
                }
                $out[] = $it;
            }
        }
        return $out;
    }
}
