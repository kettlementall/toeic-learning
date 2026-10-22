<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `next_review_at` used to be an appointment the scheduler assigned; it now
 * only says when a card is ripe (last review + its own interval). The old
 * rebalance moved cards off their interval to flatten a daily quota, and
 * nothing walks those deferrals back any more — a card parked past its own
 * interval would simply stall there — so this resets them once.
 *
 * Only dates that sit *later* than the card earned are touched: a card due
 * sooner than its interval (demote, queueForReview put cards on today on
 * purpose) is left exactly as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $today = Carbon::today();

        DB::table('user_words')
            ->select('id', 'interval_days', 'last_reviewed_at', 'next_review_at')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($today) {
                foreach ($rows as $row) {
                    $ripe = $row->last_reviewed_at
                        ? Carbon::parse($row->last_reviewed_at)->startOfDay()
                            ->addDays(max(1, (int) $row->interval_days))
                        : $today;

                    // never earned a later date than this
                    if ($ripe->lessThan($today)) {
                        $ripe = $today;
                    }

                    if ($row->next_review_at && Carbon::parse($row->next_review_at)->lessThanOrEqualTo($ripe)) {
                        continue; // already due at or before it is ripe
                    }

                    DB::table('user_words')->where('id', $row->id)->update([
                        'next_review_at' => $ripe,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // The deferrals were an artefact of a scheduler that no longer exists;
        // there is nothing meaningful to restore them to.
    }
};
