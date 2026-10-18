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
     * Pick a due date `$intervalDays` out, nudged onto the least loaded day
     * within the tolerance window so reviews never cluster onto one date.
     */
    private function schedule(UserWord $w, int $intervalDays): Carbon
    {
        $target = Carbon::today()->addDays($intervalDays);

        // learning steps stay exact — moving them defeats the point
        if ($intervalDays <= 2) {
            return $target;
        }

        $slack = (int) floor($intervalDays * (float) config('srs.load_balance_tolerance'));
        if ($slack < 1) {
            return $target;
        }

        $first = Carbon::today()->addDays(max(1, $intervalDays - $slack));
        $last = Carbon::today()->addDays($intervalDays + $slack);

        $load = $this->dailyLoad($w->user_id, $first, $last, $w->id);

        $best = $target;
        $bestScore = null;
        for ($day = $first->copy(); $day->lte($last); $day->addDay()) {
            $count = $load[$day->toDateString()] ?? 0;
            // fewest cards wins; ties break toward the intended date
            $score = [$count, abs($day->diffInDays($target, false))];
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = $day->copy();
            }
        }

        return $best;
    }

    /**
     * How many cards are already scheduled on each day of a date range.
     *
     * @return array<string,int>  'Y-m-d' => count
     */
    private function dailyLoad(int $userId, Carbon $from, Carbon $to, ?int $excludeId = null): array
    {
        return UserWord::forUser($userId)
            ->active()
            ->whereBetween('next_review_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->select(DB::raw('DATE(next_review_at) as d'), DB::raw('COUNT(*) as c'))
            ->groupBy('d')
            ->pluck('c', 'd')
            ->all();
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
     * Today's review queue: the most urgent cards, never more than the daily
     * capacity. Rebalances first so an absence turns into a few full days
     * rather than one impossible pile.
     *
     * $limit overrides the capacity for a caller that is not a day's session —
     * a quiz asking for its own review portion, where the capacity is the
     * calendar's unit of work, not a ceiling on one sitting.
     */
    public function dueWords(?int $userId = null, ?int $limit = null): Collection
    {
        $userId ??= auth()->id();

        $this->rebalance($userId);

        return $this->dueQuery($userId)
            ->orderBy($this->priorityExpression())
            ->orderByDesc($this->urgencyExpression())
            ->limit($limit ?? (int) config('srs.daily_capacity'))
            ->get();
    }

    /** Size of today's queue (capped at the daily capacity). */
    public function dueCount(?int $userId = null): int
    {
        return min(
            $this->backlogCount($userId),
            (int) config('srs.daily_capacity')
        );
    }

    /** Every card currently past its due date, uncapped. */
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
     * otherwise. Sorted first, so those words always get today's slots and are
     * never the ones rebalance pushes forward. Mirrors UserWord::freshSearch().
     */
    private function priorityExpression(): \Illuminate\Database\Query\Expression
    {
        return DB::raw("CASE WHEN source = 'search' AND last_reviewed_at IS NULL AND COALESCE(lapses, 0) = 0 THEN 0 ELSE 1 END");
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
     * How far a card has gone past its own interval, as a multiple of it:
     * days since its last review over the interval it was given.
     *
     * Unlike urgencyExpression() this ignores next_review_at, so it is the one
     * to order by when every candidate sits in the future. A ratio of 1 or more
     * means the interval has already elapsed and the card is really overdue
     * (the SQL side of isDeferred()); under 1 means it is genuinely early. So
     * ordering by it descending puts the deferred pile first, most overdue
     * first, and then the early cards, nearest due first. Cards never reviewed
     * have no ratio and sort last, which is right — pulling a brand new card
     * forward gains nothing.
     */
    private function elapsedRatioExpression(): \Illuminate\Database\Query\Expression
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return DB::raw("(julianday(date('now')) - julianday(date(last_reviewed_at))) / max(interval_days, 1)");
        }

        return DB::raw('DATEDIFF(CURDATE(), DATE(last_reviewed_at)) / GREATEST(interval_days, 1)');
    }

    /**
     * Spread any overdue cards beyond today's capacity onto the next days that
     * still have room. Idempotent and cheap when nothing is overdue, so it can
     * run on every quiz build and review session — the schedule repairs itself
     * without a cron, which is the only thing keeping it honest here (nothing
     * runs the Laravel scheduler, and the review page is optional).
     *
     * @return int  number of cards moved
     */
    public function rebalance(?int $userId = null): int
    {
        $userId ??= auth()->id();
        $capacity = (int) config('srs.daily_capacity');

        // Only a pile left from earlier days is spread. A card dated today is
        // today's load, and some land there on purpose — a wrong quiz answer,
        // a pull-forward, an AI suggestion — so the same day never takes them
        // back off; they join the stale pile tomorrow if they go untouched.
        $stale = fn () => UserWord::forUser($userId)
            ->active()
            ->where('next_review_at', '<', Carbon::today());

        $overdue = $stale()->count();
        if ($overdue <= $capacity) {
            return 0;
        }

        // keep the most urgent `capacity` cards for today, push the rest
        $overflow = $stale()
            ->orderBy($this->priorityExpression())
            ->orderByDesc($this->urgencyExpression())
            ->offset($capacity)
            ->limit($overdue)
            ->get(['id']);

        // Read the whole stretch of calendar this could touch in one query. A
        // large pile walks hundreds of days looking for room, and counting each
        // of them separately turned this into hundreds of queries on a path the
        // quiz now takes on every build.
        $day = Carbon::today();
        $horizon = $day->copy()->addDays(max(60, intdiv($overdue, max(1, $capacity)) + 30));
        $load = $this->dailyLoad($userId, $day->copy()->addDay(), $horizon);

        $room = 0;
        $moved = 0;

        foreach ($overflow as $card) {
            while ($room < 1) {
                $day->addDay();

                if ($day->greaterThan($horizon)) {
                    $horizon = $day->copy()->addDays(60);
                    $load = $this->dailyLoad($userId, $day, $horizon);
                }

                $room = max(0, $capacity - ($load[$day->toDateString()] ?? 0));
            }

            UserWord::whereKey($card->id)->update(['next_review_at' => $day->copy()->startOfDay()]);
            $load[$day->toDateString()] = ($load[$day->toDateString()] ?? 0) + 1;
            $room--;
            $moved++;
        }

        return $moved;
    }

    /**
     * Make up to $n more cards due today, nearest scheduled date first.
     *
     * Two kinds of card sit in the future and they are not the same thing. One
     * rebalance deferred for load — its own interval has already elapsed — is
     * really overdue, so it is only moved back to today: the interval is left
     * alone and grade()'s late-review credit applies as it should. A card that
     * is genuinely early instead has its interval re-anchored to the real gap,
     * so drilling it ahead of time cannot stretch it (same rule as
     * queueForReview).
     *
     * Ordering is by elapsedRatioExpression() — how far past its own interval
     * each card has gone — so the deferred pile goes first, most overdue first,
     * and only once it runs out does this reach genuinely early cards, nearest
     * due first, where the loss is smallest. Ordering by next_review_at or by
     * urgencyExpression() instead would favour long-interval mature cards that
     * are barely overdue over short-interval ones many times past their
     * interval, and would let a word taught minutes ago (due tomorrow, nearer
     * than the whole deferred pile) be dragged straight back.
     *
     * Quizzes use this once today's queue is spent, so the extra questions in a
     * long sitting keep draining the backlog instead of logging early peeks.
     *
     * @param  string[]  $exclude  words already picked
     * @return string[]  the words made due
     */
    public function pullForward(int $n, array $exclude = [], ?int $userId = null): array
    {
        $userId ??= auth()->id();

        if ($n < 1) {
            return [];
        }

        $today = Carbon::today();

        $cards = UserWord::forUser($userId)
            ->active()
            ->notClearedToday()
            ->when($exclude, fn ($q) => $q->whereNotIn('word', $exclude))
            ->where('next_review_at', '>', $today)
            ->orderByDesc($this->elapsedRatioExpression())
            ->orderBy('next_review_at')
            ->limit($n)
            ->get();

        $pulled = [];

        foreach ($cards as $uw) {
            if (! $this->isDeferred($uw)) {
                $uw->interval_days = $this->elapsedInterval($uw) ?? $uw->interval_days;
            }

            $uw->next_review_at = $today->copy();
            $uw->save();

            $pulled[] = $uw->word;
        }

        return $pulled;
    }

    /**
     * Whether this card's own interval has already run out — it is scheduled
     * in the future only because rebalance moved it off a full day.
     */
    private function isDeferred(UserWord $w): bool
    {
        if (! $w->last_reviewed_at) {
            return false;
        }

        return $w->last_reviewed_at->copy()->startOfDay()
            ->addDays(max(1, (int) $w->interval_days))
            ->lessThanOrEqualTo(Carbon::today());
    }

    /**
     * The interval an early review has actually earned: days since the last
     * one. Null for a card with no review to measure from.
     */
    private function elapsedInterval(UserWord $w): ?int
    {
        if (! $w->last_reviewed_at || $w->repetitions < 1) {
            return null;
        }

        return max(1, (int) $w->last_reviewed_at->copy()->startOfDay()->diffInDays(Carbon::today()));
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
