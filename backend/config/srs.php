<?php

return [

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
    | This is where intake pressure is set, and it is the one real dial. It is
    | a share of *every quiz*, so intake scales with how much the learner
    | studies: ten quizzes a day is ten times the new words, and a new word
    | costs roughly seven reviews over its first few years. Nothing downstream
    | absorbs that — the queue simply grows — so raising this does not buy
    | more learning, it lengthens the tail of words waiting their turn.
    |
    */
    'new_word_share' => 0.2,

];
