<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\ReviewLog;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Support\Facades\DB;

class QuizBuilderService
{
    public const GRAMMAR_POINTS = [
        'word_form', 'tense_voice', 'preposition', 'conjunction', 'pronoun',
        'relative', 'comparison', 'agreement', 'collocation', 'vocab_in_context',
    ];

    public function __construct(private ClaudeService $claude)
    {
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
        return match ($mode) {
            'weak' => $this->weakWords($n),
            'custom' => $this->customWords($n, $opts),
            default => $this->smartMix($n, $opts),
        };
    }

    /**
     * Smart mix: ~70% review (weak/due) + ~30% new words.
     */
    private function smartMix(int $n, array $opts): array
    {
        $reviewN = (int) ceil($n * 0.7);

        $review = $this->weakWords($reviewN);

        // top up review portion from any user words if not enough
        if (count($review) < $reviewN) {
            $more = UserWord::forUser()
                ->whereNotIn('word', $review)
                ->inRandomOrder()
                ->limit($reviewN - count($review))
                ->pluck('word')
                ->all();
            $review = array_merge($review, $more);
        }

        // fill the remainder with new words (~30% when review is full; more if the
        // library was too small to fill the review portion).
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
     * Weak / overdue words from the user's library, picked by weighted random
     * sampling (biased toward less-familiar & overdue words, but with variety).
     *
     * Words tested in the last few quizzes are down-weighted (cooldown) so the
     * selection rotates instead of drilling the same cluster of hard words over
     * and over. Cooldown only reweights — it never excludes — so we never run
     * short even if the whole library was recently seen.
     */
    private function weakWords(int $n): array
    {
        if ($n < 1) {
            return [];
        }

        $candidates = UserWord::forUser()->get(['word', 'ease_factor', 'next_review_at']);
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

        $query = Word::whereNotIn('word', $ownedWords);
        if (! empty($opts['category'])) {
            $query->where('category', $opts['category']);
        }
        if (! empty($opts['level'])) {
            $query->where('level', $opts['level']);
        }

        $fromSeed = $query->inRandomOrder()->limit($n)->pluck('word')->all();

        if (count($fromSeed) >= $n) {
            return $fromSeed;
        }

        // not enough -> ask Claude to generate fresh words
        $need = $n - count($fromSeed);
        $generated = $this->claude->generateNewWords($need, $opts['level'] ?? null, $opts['category'] ?? null);

        $newWords = [];
        foreach ($generated as $g) {
            if (empty($g['word'])) {
                continue;
            }
            $word = trim(strtolower($g['word']));
            if (in_array($word, $ownedWords, true) || in_array($word, $fromSeed, true)) {
                continue;
            }
            // persist generated word into the words cache
            Word::updateOrCreate(['word' => $word], [
                'part_of_speech' => $g['part_of_speech'] ?? null,
                'definition_zh' => $g['definition_zh'] ?? null,
                'example' => $g['example'] ?? null,
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
