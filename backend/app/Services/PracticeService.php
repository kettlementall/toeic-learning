<?php

namespace App\Services;

use App\Models\PracticeAnswer;
use App\Models\PracticeAttempt;
use App\Models\PracticeQuestion;
use App\Models\PracticeSet;
use Illuminate\Support\Facades\DB;

/**
 * Sittings over the real-test practice bank.
 *
 * Writes only practice_attempts / practice_answers. It must never touch
 * quizzes, quiz_questions, user_words or review_logs: those carry the
 * vocabulary SM-2 schedule and the quiz builder's cooldowns (see the
 * create_practice_tables migration).
 */
class PracticeService
{
    public const MODES = ['mock', 'practice'];

    public function startAttempt(int $userId, PracticeSet $set, string $mode): PracticeAttempt
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'practice';
        $ids = $set->questions()->pluck('id')->all();

        return PracticeAttempt::create([
            'user_id' => $userId,
            'set_id' => $set->id,
            'mode' => $mode,
            'section' => $set->section,
            'question_ids' => $ids,
            'total' => count($ids),
            'time_limit_seconds' => $mode === 'mock' && $set->section === 'reading' ? $set->time_limit_seconds : null,
            'started_at' => now(),
        ]);
    }

    /**
     * A practice sitting over the questions whose latest answer was wrong.
     * Returns null when there is nothing to retry.
     */
    public function startRetry(int $userId, string $section): ?PracticeAttempt
    {
        $ids = $this->wrongQuestionIds($userId, $section);
        if (! $ids) {
            return null;
        }

        // keep paper order inside each set so passages stay together
        $ordered = PracticeQuestion::whereIn('id', $ids)
            ->orderBy('set_id')->orderBy('sort')
            ->limit((int) config('practice.retry_max', 30))
            ->pluck('id')->all();

        return PracticeAttempt::create([
            'user_id' => $userId,
            'set_id' => null,
            'mode' => 'retry',
            'section' => $section,
            'question_ids' => $ordered,
            'total' => count($ordered),
            'started_at' => now(),
        ]);
    }

    /**
     * Question ids in a section whose most recent answer by this user was wrong.
     *
     * @return int[]
     */
    public function wrongQuestionIds(int $userId, string $section): array
    {
        $latest = PracticeAnswer::where('user_id', $userId)
            ->selectRaw('MAX(id) as id')
            ->groupBy('question_id');

        return PracticeAnswer::whereIn('practice_answers.id', $latest)
            ->where('practice_answers.is_correct', false)
            ->join('practice_questions', 'practice_questions.id', '=', 'practice_answers.question_id')
            ->join('practice_sets', 'practice_sets.id', '=', 'practice_questions.set_id')
            ->where('practice_sets.section', $section)
            ->pluck('practice_answers.question_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Grade a sitting. Idempotent: a completed attempt is left as it was.
     *
     * @param  array<int|string, string|null>  $answers  question id => letter
     */
    public function grade(PracticeAttempt $attempt, array $answers): PracticeAttempt
    {
        if ($attempt->isCompleted()) {
            return $attempt;
        }

        DB::transaction(function () use ($attempt, $answers) {
            $score = 0;
            foreach ($attempt->orderedQuestions() as $q) {
                $choice = strtoupper(trim((string) ($answers[$q->id] ?? '')));
                $choice = preg_match('/^[A-D]$/', $choice) ? $choice : null;
                $correct = $choice !== null && $choice === $q->answer;
                $score += $correct ? 1 : 0;

                PracticeAnswer::create([
                    'attempt_id' => $attempt->id,
                    'user_id' => $attempt->user_id,
                    'question_id' => $q->id,
                    'choice' => $choice,
                    'is_correct' => $correct,
                ]);
            }

            $attempt->update([
                'score' => $score,
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        });

        return $attempt->refresh();
    }

    /**
     * Accuracy over every answer this user has given, by section, part and skill.
     *
     * @return array{sections: array, parts: array, skills: array}
     */
    public function stats(int $userId): array
    {
        $rows = PracticeAnswer::where('practice_answers.user_id', $userId)
            ->join('practice_questions', 'practice_questions.id', '=', 'practice_answers.question_id')
            ->join('practice_sets', 'practice_sets.id', '=', 'practice_questions.set_id')
            ->get(['practice_sets.section', 'practice_questions.part', 'practice_questions.skill', 'practice_answers.is_correct']);

        $tally = function ($key) use ($rows) {
            return $rows->groupBy($key)->map(fn ($g) => [
                'total' => $g->count(),
                'correct' => $g->where('is_correct', true)->count(),
                'rate' => round($g->where('is_correct', true)->count() / $g->count() * 100),
            ])->sortKeys()->all();
        };

        $labels = config('practice.skills');
        $skills = collect($tally('skill'))
            ->reject(fn ($v, $k) => $k === '' || $k === null)
            ->map(fn ($v, $k) => $v + ['label' => $labels[$k] ?? $k])
            ->sortBy('rate')
            ->all();

        return [
            'sections' => $tally('section'),
            'parts' => $tally('part'),
            'skills' => $skills,
        ];
    }
}
