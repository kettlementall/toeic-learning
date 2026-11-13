<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Part 5 grammar quizzes
    |--------------------------------------------------------------------------
    |
    | `window_days`: how far back the adaptive selector looks at the learner's
    | Part 5 answers when weighting grammar points, so a weakness that has been
    | fixed stops being drilled. Same span as the score estimate.
    |
    | `target_score`: the TOEIC total the generated questions are pitched at.
    | 860 is the gold certificate.
    |
    */
    'part5' => [
        'window_days' => 90,
        'target_score' => 860,
    ],

];
