<?php

namespace App\Services;

use App\Models\ReviewLog;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SpacedRepetitionService
{
    /** Cumulative failures at which a word is flagged as a "leech". */
    public const LEECH_THRESHOLD = 4;

    /**
     * Quality credited for a correct multiple-choice answer. Recognising a word
     * among four options is not recall, so it earns a "good" rather than an
     * "easy": at quality 4 the SM-2 ease delta is exactly zero, so the interval
     * still grows by `interval x ease` but a lucky hit cannot raise ease.
     */
    public const QUIZ_CORRECT_QUALITY = 4;

    /**
     * Apply the SM-2 algorithm after a self-graded review (quality 0-5).
     */
    public function grade(UserWord $w, int $quality): void
    {
        $quality = max(0, min(5, $quality));

        // a word already failed today (quiz, review) is not penalised again:
        // one bad day is one lapse, not three
        $repeatFailure = $quality < 3 && $this->lapsedToday($w);

        if ($quality < 3) {
            // failed recall -> reset
            $w->repetitions = 0;
            $w->interval_days = 1;
            if (! $repeatFailure) {
                $w->lapses = ($w->lapses ?? 0) + 1;
            }
        } else {
            $w->repetitions += 1;
            $w->interval_days = match ($w->repetitions) {
                1 => 1,
                2 => 6,
                // Recalling a card that was already overdue proves the interval
                // was too short, so credit half the extra time it survived.
                default => (int) round($this->creditedInterval($w) * $w->ease_factor),
            };
        }

        // update ease factor
        if (! $repeatFailure) {
            $ef = $w->ease_factor + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02));
            $w->ease_factor = max(1.3, round($ef, 2));
        }

        $w->last_reviewed_at = now();
        $w->next_review_at = $this->schedule($w, max(1, $w->interval_days));

        // a word that keeps failing leaves the rotation instead of clogging it
        if ($w->lapses >= config('srs.leech_suspend_at') && ! $w->suspended_at) {
            $w->suspended_at = now();
        }

        $w->save();

        $this->log($w, $quality);
    }

    /**
     * Interval to grow from: the scheduled interval plus half of however long
     * the card actually went unseen past its due date (SM-2 late-review bonus).
     */
    private function creditedInterval(UserWord $w): int
    {
        $scheduled = max(1, (int) $w->interval_days);

        if (! $w->last_reviewed_at) {
            return $scheduled;
        }

        $elapsed = (int) $w->last_reviewed_at->diffInDays(now());
        $overdue = max(0, $elapsed - $scheduled);

        return $scheduled + intdiv($overdue, 2);
    }

    /**
     * When this card is ripe again: `$intervalDays` out, and nothing else.
     *
     * A date here is a statement about forgetting, not an appointment. Nothing
     * reads it as "the day this must be done" — the queue serves whatever is
     * ripe, most urgent first — so there is no calendar to keep flat and no
     * reason to nudge a card off its own interval. Anchored to `today()`, not
     * `now()`, so a day is a whole day.
     */
    private function schedule(UserWord $w, int $intervalDays): Carbon
    {
        return Carbon::today()->addDays($intervalDays);
    }

    /**
     * A correct quiz answer, worth QUIZ_CORRECT_QUALITY. Counts as a review
     * only when the card is due (or has never been reviewed); a multiple-choice
     * hit on a card that is not due yet is an early peek, and grading it would
     * stretch the interval as if a full one had passed. Those are logged for
     * stats but leave the schedule alone.
     */
    public function quizCorrect(string $word): void
    {
        $uw = UserWord::forUser()->active()->where('word', trim(strtolower($word)))->first();
        if (! $uw) {
            return;
        }

        $due = ! $uw->next_review_at || $uw->next_review_at <= now();

        if ($due || ! $uw->last_reviewed_at) {
            $this->grade($uw, self::QUIZ_CORRECT_QUALITY);
        } else {
            $this->log($uw, self::QUIZ_CORRECT_QUALITY);
        }
    }

    /**
     * The review queue: everything ripe, most urgent first, and the caller
     * takes as many as it wants.
     *
     * There is no daily budget. How much gets reviewed is set by how much the
     * learner studies — ten quizzes eat ten quizzes' worth of the queue — not
     * by a number of cards a day apportioned in advance. What a day decides is
     * only which cards have become ripe, because that is what forgetting runs
     * on.
     *
     * Order: words the learner looked up and has not been tested on, then
     * anything missed today, then by relative overdueness.
     */
    public function dueWords(?int $userId = null, ?int $limit = null): Collection
    {
        $userId ??= auth()->id();

        return $this->dueQuery($userId)
            ->orderBy($this->priorityExpression())
            ->orderByRaw($this->missedTodaySql().' DESC', [Carbon::today()])
            ->orderByDesc($this->urgencyExpression())
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();
    }

    /** Every card currently ripe. */
    public function backlogCount(?int $userId = null): int
    {
        return $this->dueQuery($userId ?? auth()->id())->count();
    }

    private function dueQuery(int $userId)
    {
        return UserWord::forUser($userId)
            ->active()
            ->notClearedToday()
            ->where('next_review_at', '<=', now());
    }

    /**
     * 0 for words the user just looked up and has not been tested on yet, 1
     * otherwise. Sorted first, so a word the learner went and asked about is
     * never buried under the library. Mirrors UserWord::freshSearch().
     */
    private function priorityExpression(): \Illuminate\Database\Query\Expression
    {
        return DB::raw("CASE WHEN source = 'search' AND last_reviewed_at IS NULL AND COALESCE(lapses, 0) = 0 THEN 0 ELSE 1 END");
    }

    /**
     * 1 for a card missed today, 0 otherwise — ordered descending, so a miss
     * comes back round in the same sitting.
     *
     * Without this a miss sinks to the bottom of the queue. `demote()` puts it
     * back on today, which makes it exactly 0 days overdue, while every stale
     * card in the library is further past its due date and so scores higher on
     * urgency. In a library of any size that buries the one card whose recall
     * just measured at zero behind hundreds that are merely late. Relearning
     * comes before reviewing.
     *
     * Returns SQL, not an Expression, because it takes a binding.
     */
    private function missedTodaySql(): string
    {
        return '(SELECT COUNT(*) FROM review_logs rl'
            .' WHERE rl.user_word_id = user_words.id AND rl.quality < 3 AND rl.reviewed_at >= ?)';
    }

    /**
     * Relative overdueness: how far past its due date a card is as a multiple
     * of its own interval. A 2-day card a week late is far closer to being
     * forgotten than a 200-day card a week late, so it comes first.
     */
    private function urgencyExpression(): \Illuminate\Database\Query\Expression
    {
        // sqlite (used by the test suite) has neither DATEDIFF nor GREATEST
        if (DB::connection()->getDriverName() === 'sqlite') {
            return DB::raw("(julianday('now') - julianday(next_review_at)) / max(interval_days, 1)");
        }

        return DB::raw('DATEDIFF(NOW(), next_review_at) / GREATEST(interval_days, 1)');
    }

    /**
     * Weakness feedback: a word was answered wrong in a quiz.
     * Create/find the user_word, lower ease factor, schedule for today.
     */
    public function demote(string $word, string $source = 'quiz_weak'): UserWord
    {
        $word = trim(strtolower($word));
        $wordModel = Word::where('word', $word)->first();

        $uw = UserWord::firstOrNew(['user_id' => auth()->id(), 'word' => $word]);

        // only the first failure of the day costs a lapse and ease
        $repeatFailure = $this->lapsedToday($uw);

        if (! $uw->exists) {
            $uw->word_id = $wordModel?->id;
            $uw->source = $source;
            $uw->ease_factor = 2.2; // start a touch harder
            $uw->repetitions = 0;
            $uw->interval_days = 0;
        } else {
            if (! $repeatFailure) {
                $uw->ease_factor = max(1.3, round($uw->ease_factor - 0.2, 2));
            }
            $uw->repetitions = 0;
            $uw->interval_days = 0;
        }
        if (! $repeatFailure) {
            $uw->lapses = ($uw->lapses ?? 0) + 1;
        }
        $uw->next_review_at = Carbon::today();

        if ($uw->lapses >= config('srs.leech_suspend_at') && ! $uw->suspended_at) {
            $uw->suspended_at = now();
        }

        $uw->save();

        $this->log($uw, 1);

        return $uw;
    }

    /**
     * A word the AI review thinks is worth another look. Unlike demote() this
     * is not a failure: no lapse, no ease change, no failed log. New words join
     * the library due today; an existing word is pulled forward to today, with
     * its interval re-anchored to the real gap since its last review so the
     * early review cannot stretch it. Suspended words are left alone.
     */
    public function queueForReview(string $word, string $source = 'ai_review'): UserWord
    {
        $word = trim(strtolower($word));

        $uw = UserWord::firstOrNew(['user_id' => auth()->id(), 'word' => $word]);

        if (! $uw->exists) {
            $uw->word_id = Word::where('word', $word)->value('id');
            $uw->source = $source;
            $uw->repetitions = 0;
            $uw->interval_days = 0;
            $uw->next_review_at = Carbon::today();
            $uw->save();

            return $uw;
        }

        if ($uw->suspended_at || ($uw->next_review_at && $uw->next_review_at <= Carbon::today())) {
            return $uw;
        }

        $uw->interval_days = $this->elapsedInterval($uw) ?? $uw->interval_days;
        $uw->next_review_at = Carbon::today();
        $uw->save();

        return $uw;
    }

    /**
     * The interval an early review has actually earned: days since the last
     * one. Null for a card with no review to measure from.
     *
     * Pulling a card forward must not stretch it. Grading it on an interval it
     * never served would read as evidence the memory survived that long, when
     * all it survived is the shorter gap.
     */
    private function elapsedInterval(UserWord $w): ?int
    {
        if (! $w->last_reviewed_at || $w->repetitions < 1) {
            return null;
        }

        return max(1, (int) $w->last_reviewed_at->copy()->startOfDay()->diffInDays(Carbon::today()));
    }

    /** Whether this card already recorded a failure today. */
    private function lapsedToday(UserWord $w): bool
    {
        if (! $w->exists) {
            return false;
        }

        return ReviewLog::where('user_word_id', $w->id)
            ->where('quality', '<', 3)
            ->where('reviewed_at', '>=', Carbon::today())
            ->exists();
    }

    /** Put a suspended word back into the rotation, relearning from scratch. */
    public function resume(UserWord $w): void
    {
        $w->suspended_at = null;
        $w->lapses = 0;
        $w->repetitions = 0;
        $w->interval_days = 1;
        $w->ease_factor = max(1.3, min(2.5, $w->ease_factor));
        $w->next_review_at = Carbon::today();
        $w->save();
    }

    private function log(UserWord $w, int $quality): void
    {
        $dict = $w->dictionary;
        ReviewLog::create([
            'user_id' => $w->user_id,
            'user_word_id' => $w->id,
            'category' => $dict?->category,
            'part_of_speech' => $dict?->part_of_speech,
            'quality' => $quality,
            'reviewed_at' => now(),
        ]);
    }
}
