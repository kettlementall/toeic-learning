<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily review capacity
    |--------------------------------------------------------------------------
    |
    | The number of cards a day is expected to carry. Cards are spread onto
    | days that still have room, and anything that overflows a day is pushed
    | forward rather than piling up as "overdue" — so the backlog never grows
    | past this number, provided something actually calls rebalance().
    |
    | Quiz creation is what calls it (QuizController@store and
    | QuizBuilderService::smartMix); nothing runs the Laravel scheduler in this
    | deployment and the /review page is optional, so the quiz is the surface
    | that keeps the schedule honest.
    |
    | Derive it from the quiz the learner actually takes: one 10-question quiz
    | leaves `10 - floor(10 * new_word_share)` = 8 slots for reviews. Set this
    | higher and a residue accumulates that nothing drains. It is not a ceiling
    | on one sitting — pullForward() makes more cards due when a quiz asks for
    | more than today holds.
    |
    */
    'daily_capacity' => 8,

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
    | New word share
    |--------------------------------------------------------------------------
    |
    | The fraction of a quiz given to words the learner has never seen. A flat
    | guaranteed share, not a budget: quizzes are the only study surface, so
    | one that carries no new words means the curated TOEIC list never
    | advances. Quizzes of five or more questions always get at least one.
    |
    | This is where intake pressure is set, and it is a real cost: a new word
    | generates roughly seven reviews over its first few years, so two a day
    | asks for ~14 review-slots a day that a 10-question quiz does not have.
    | The schedule absorbs the difference by deferring the least urgent cards
    | (rebalance), which is a deliberate trade — drill what is closest to being
    | forgotten, and let the long tail slip — not a backlog to be cleared.
    |
    */
    'new_word_share' => 0.2,

];
