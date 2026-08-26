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
     * Apply the SM-2 algorithm after a self-graded review (quality 0-5).
     */
    public function grade(UserWord $w, int $quality): void
    {
        $quality = max(0, min(5, $quality));

        if ($quality < 3) {
            // failed recall -> reset
            $w->repetitions = 0;
            $w->interval_days = 1;
            $w->lapses = ($w->lapses ?? 0) + 1;
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
        $ef = $w->ease_factor + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02));
        $w->ease_factor = max(1.3, round($ef, 2));

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
     * Drive SM-2 by word string (used by quiz grading). No-op if the word
     * is not in the user's library.
     */
    public function gradeByWord(string $word, int $quality): void
    {
        $uw = UserWord::forUser()->where('word', trim(strtolower($word)))->first();
        if ($uw) {
            $this->grade($uw, $quality);
        }
    }

    /**
     * Today's review queue: the most urgent cards, never more than the daily
     * capacity. Rebalances first so an absence turns into a few full days
     * rather than one impossible pile.
     */
    public function dueWords(?int $userId = null): Collection
    {
        $userId ??= auth()->id();

        $this->rebalance($userId);

        return $this->dueQuery($userId)
            ->orderByDesc($this->urgencyExpression())
            ->limit((int) config('srs.daily_capacity'))
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
            ->where('next_review_at', '<=', now());
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
     * Spread any overdue cards beyond today's capacity onto the next days that
     * still have room. Idempotent and cheap when nothing is overdue, so it can
     * run on every review session — the schedule repairs itself without a cron.
     *
     * @return int  number of cards moved
     */
    public function rebalance(?int $userId = null): int
    {
        $userId ??= auth()->id();
        $capacity = (int) config('srs.daily_capacity');

        $overdue = $this->dueQuery($userId)->count();
        if ($overdue <= $capacity) {
            return 0;
        }

        // keep the most urgent `capacity` cards for today, push the rest
        $overflow = $this->dueQuery($userId)
            ->orderByDesc($this->urgencyExpression())
            ->offset($capacity)
            ->limit($overdue)
            ->get(['id']);

        $day = Carbon::today();
        $room = 0;
        $moved = 0;

        foreach ($overflow as $card) {
            while ($room < 1) {
                $day->addDay();
                $scheduled = UserWord::forUser($userId)
                    ->active()
                    ->whereDate('next_review_at', $day)
                    ->count();
                $room = max(0, $capacity - $scheduled);
            }

            UserWord::whereKey($card->id)->update(['next_review_at' => $day->copy()->startOfDay()]);
            $room--;
            $moved++;
        }

        return $moved;
    }

    /**
     * Weakness feedback: a word was answered wrong (quiz) or flagged by AI review.
     * Create/find the user_word, lower ease factor, schedule for today.
     */
    public function demote(string $word, string $source = 'quiz_weak'): UserWord
    {
        $word = trim(strtolower($word));
        $wordModel = Word::where('word', $word)->first();

        $uw = UserWord::firstOrNew(['user_id' => auth()->id(), 'word' => $word]);
        if (! $uw->exists) {
            $uw->word_id = $wordModel?->id;
            $uw->source = $source;
            $uw->ease_factor = 2.2; // start a touch harder
            $uw->repetitions = 0;
            $uw->interval_days = 0;
        } else {
            $uw->ease_factor = max(1.3, round($uw->ease_factor - 0.2, 2));
            $uw->repetitions = 0;
            $uw->interval_days = 0;
        }
        $uw->lapses = ($uw->lapses ?? 0) + 1;
        $uw->next_review_at = Carbon::today();

        if ($uw->lapses >= config('srs.leech_suspend_at') && ! $uw->suspended_at) {
            $uw->suspended_at = now();
        }

        $uw->save();

        $this->log($uw, 1);

        return $uw;
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
