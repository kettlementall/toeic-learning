<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily review capacity
    |--------------------------------------------------------------------------
    |
    | The maximum number of cards a review session will ever surface in one
    | day. This is the load ceiling the whole scheduler is built around: cards
    | are spread onto days that still have room, and anything that overflows a
    | day is pushed forward rather than piling up as "overdue". A backlog can
    | therefore never grow past this number.
    |
    */
    'daily_capacity' => 40,

    /*
    |--------------------------------------------------------------------------
    | Load balancing
    |--------------------------------------------------------------------------
    |
    | When a card is scheduled, its due date may be nudged by up to this
    | fraction of the interval to land on a less crowded day (a 100-day
    | interval with 0.15 tolerance may move +/- 15 days). Keeps the calendar
    | flat instead of forming clusters that all come due on the same day.
    | Cards in the 1-2 day learning steps are never moved.
    |
    */
    'load_balance_tolerance' => 0.15,

    /*
    |--------------------------------------------------------------------------
    | Leeches
    |--------------------------------------------------------------------------
    |
    | `flag_at` lapses marks a word as chronically failed (surfaced on the
    | dashboard). At `suspend_at` lapses it is automatically pulled out of the
    | review rotation entirely — drilling a word that has failed this often is
    | not working and it should be relearned deliberately instead.
    |
    */
    'leech_flag_at' => 4,
    'leech_suspend_at' => 8,

    /*
    |--------------------------------------------------------------------------
    | New word intake gate
    |--------------------------------------------------------------------------
    |
    | Quizzes stop mixing in new words while the outstanding review backlog is
    | at or above this size, and taper the new-word share as it approaches.
    | Stops new material from being added faster than it can be digested.
    |
    */
    'intake_gate' => 120,
    'new_word_share' => 0.3,

];
