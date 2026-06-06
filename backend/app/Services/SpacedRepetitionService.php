<?php

namespace App\Services;

use App\Models\ReviewLog;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
                default => (int) round($w->interval_days * $w->ease_factor),
            };
        }

        // update ease factor
        $ef = $w->ease_factor + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02));
        $w->ease_factor = max(1.3, round($ef, 2));

        $w->last_reviewed_at = now();
        $w->next_review_at = now()->addDays(max(1, $w->interval_days));
        $w->save();

        $this->log($w, $quality);
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
     * Words due for review (next_review_at <= now) for the current user.
     */
    public function dueWords(): Collection
    {
        return UserWord::forUser()
            ->where('next_review_at', '<=', now())
            ->orderBy('next_review_at')
            ->get();
    }

    public function dueCount(): int
    {
        return UserWord::forUser()->where('next_review_at', '<=', now())->count();
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
        $uw->save();

        $this->log($uw, 1);

        return $uw;
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
