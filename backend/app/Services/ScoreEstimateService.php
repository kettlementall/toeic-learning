<?php

namespace App\Services;

use App\Models\QuizQuestion;
use App\Models\UserWord;

/**
 * A rough TOEIC Reading score (5–495) from the learner's own quiz answers.
 *
 * Reads `quiz_questions.is_correct` and nothing else. `review_logs` is not a
 * record of answers: boostGrammar() writes quality-1 rows for every point the
 * AI review suggests, which would read as a wall of wrong answers.
 *
 * Vocabulary is measured on **first exposure** only. A vocab quiz mostly asks
 * about words already being studied, so its accuracy reports retention of the
 * library, not how much of the TOEIC list the learner knew. The first time a
 * word that entered through a quiz (`source=quiz_new`) is asked, the answer
 * says whether it was known beforehand — a sample of unstudied words.
 *
 * Listening is not estimated: nothing in the app tests it.
 */
class ScoreEstimateService
{
    /** Share of the real Reading section: Part 5 30, Part 6 16, Part 7 54 of 100. */
    private const PART5 = 0.30;
    private const PART6 = 0.16;
    private const PART7 = 0.54;

    /** Roughly four in ten Part 5 items are vocabulary rather than grammar. */
    private const PART5_VOCAB_SHARE = 0.4;

    /** Narrowest range ever shown — the conversion itself is only this good. */
    private const MIN_MARGIN = 25;

    public function estimate(int $userId): array
    {
        $since = now()->subDays((int) config('toeic_estimate.window_days', 90))->toDateTimeString();

        [$p5, $n5] = $this->accuracy($userId, 'part5_grammar', $since);
        [$p7, $n7] = $this->accuracy($userId, 'news_reading', $since);
        [$pv, $nv] = $this->firstExposure($userId, $since);

        $components = compact('p5', 'n5', 'p7', 'n7', 'pv', 'nv');

        if ($n5 + $n7 + $nv < (int) config('toeic_estimate.min_total', 30) || ($n5 === 0 && $n7 === 0)) {
            return [
                'status' => 'insufficient',
                'components' => $components,
                'hint' => $this->hint($n5, $n7, $nv),
            ];
        }

        // a missing component borrows from the nearest one present
        $p5 ??= $pv ?? $p7;
        $pv ??= $p5;
        $p5eff = (1 - self::PART5_VOCAB_SHARE) * $p5 + self::PART5_VOCAB_SHARE * $pv;
        $p7 ??= $p5eff;

        // Part 6 mixes grammar-in-context and reading, so it gets half of each;
        // fold that back into one weight per measured component
        $w5 = (self::PART5 + self::PART6 / 2) * (1 - self::PART5_VOCAB_SHARE);
        $wv = (self::PART5 + self::PART6 / 2) * self::PART5_VOCAB_SHARE;
        $w7 = self::PART7 + self::PART6 / 2;

        $ease = (float) config('toeic_estimate.ai_ease_factor', 0.92);
        $raw = ($w5 * $p5 + $wv * $pv + $w7 * $p7) * $ease;

        // binomial standard error of the composite; a borrowed component adds
        // no information of its own, so it contributes nothing here
        $variance = 0.0;
        foreach ([[$w5, $p5, $n5], [$wv, $pv, $nv], [$w7, $p7, $n7]] as [$w, $p, $n]) {
            if ($n > 0) {
                $variance += $w ** 2 * $p * (1 - $p) / $n;
            }
        }
        $se = sqrt($variance) * $ease;

        $score = $this->convert($raw);
        $low = min($this->convert($raw - 1.96 * $se), $score - self::MIN_MARGIN);
        $high = max($this->convert($raw + 1.96 * $se), $score + self::MIN_MARGIN);

        return [
            'status' => 'ok',
            'score' => $this->round5($score),
            'low' => $this->round5(max(5, $low)),
            'high' => $this->round5(min(495, $high)),
            'confidence' => $this->confidence($n5, $n7, $nv),
            'components' => $components,
            'hint' => $this->hint($n5, $n7, $nv),
        ];
    }

    /** @return array{0: ?float, 1: int} accuracy and question count for one quiz type */
    private function accuracy(int $userId, string $type, string $since): array
    {
        $row = $this->answered($userId)
            ->where('quizzes.type', $type)
            ->where('quizzes.completed_at', '>=', $since)
            ->selectRaw('COUNT(*) as n, SUM(CASE WHEN quiz_questions.is_correct THEN 1 ELSE 0 END) as correct')
            ->first();

        $n = (int) $row->n;

        return [$n ? $row->correct / $n : null, $n];
    }

    /** @return array{0: ?float, 1: int} accuracy on the first question each quiz-introduced word ever got */
    private function firstExposure(int $userId, string $since): array
    {
        $introduced = UserWord::forUser($userId)
            ->where('source', 'quiz_new')
            ->pluck('word')
            ->flip();

        // first ever, then windowed: a word first met in June is not a first
        // exposure in October just because June fell out of the window
        $first = $this->answered($userId)
            ->whereIn('quizzes.type', ['vocab_mc', 'fill_blank'])
            ->whereNotNull('quiz_questions.word')
            ->orderBy('quizzes.completed_at')
            ->orderBy('quiz_questions.id')
            ->get(['quiz_questions.word', 'quiz_questions.is_correct', 'quizzes.completed_at'])
            ->unique(fn ($q) => strtolower($q->word))
            ->filter(fn ($q) => isset($introduced[strtolower($q->word)]) && $q->completed_at >= $since);

        $n = $first->count();

        return [$n ? $first->filter(fn ($q) => (bool) $q->is_correct)->count() / $n : null, $n];
    }

    private function answered(int $userId)
    {
        return QuizQuestion::query()
            ->join('quizzes', 'quizzes.id', '=', 'quiz_questions.quiz_id')
            ->where('quizzes.user_id', $userId)
            ->where('quizzes.status', 'completed')
            ->whereNotNull('quiz_questions.is_correct');
    }

    private function confidence(int $n5, int $n7, int $nv): string
    {
        $min = config('toeic_estimate.min_questions');

        // reading passages are over half the section; without them, never better than low
        if ($n7 === 0) {
            return 'low';
        }

        $met = ($n5 >= $min['part5']) + ($n7 >= $min['part7']) + ($nv >= $min['vocab']);

        return [0 => 'low', 1 => 'low', 2 => 'medium', 3 => 'high'][$met];
    }

    /** Which quiz type would most improve the estimate, or null when all are well sampled. */
    private function hint(int $n5, int $n7, int $nv): ?string
    {
        $min = config('toeic_estimate.min_questions');

        $gaps = array_filter([
            'news_reading' => 1 - $n7 / $min['part7'],
            'part5_grammar' => 1 - $n5 / $min['part5'],
            'vocab_mc' => 1 - $nv / $min['vocab'],
        ], fn ($gap) => $gap > 0);

        if (! $gaps) {
            return null;
        }
        arsort($gaps);

        return array_key_first($gaps);
    }

    /** Piecewise-linear lookup in the conversion table. */
    private function convert(float $raw): float
    {
        $points = collect(config('toeic_estimate.conversion'))
            ->mapWithKeys(fn ($score, $acc) => [(string) (float) $acc => $score])
            ->sortKeys(SORT_NUMERIC);

        $raw = max(0.0, min(1.0, $raw));
        $prevAcc = null;
        $prevScore = null;

        foreach ($points as $acc => $score) {
            $acc = (float) $acc;
            if ($raw <= $acc) {
                if ($prevAcc === null || $acc == $prevAcc) {
                    return (float) $score;
                }

                return $prevScore + ($raw - $prevAcc) / ($acc - $prevAcc) * ($score - $prevScore);
            }
            [$prevAcc, $prevScore] = [$acc, $score];
        }

        return (float) $prevScore;
    }

    private function round5(float $score): int
    {
        return (int) (round($score / 5) * 5);
    }
}
