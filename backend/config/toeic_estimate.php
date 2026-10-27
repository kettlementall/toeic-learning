<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Window
    |--------------------------------------------------------------------------
    |
    | Only quizzes completed in the last `window_days` count, so the estimate
    | follows where the learner is now rather than averaging in June.
    |
    */
    'window_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | AI ease factor
    |--------------------------------------------------------------------------
    |
    | The practice questions are generated, not drawn from real test forms, and
    | nothing calibrates their difficulty. Assume they run somewhat easier than
    | the exam and scale the composite accuracy down by this much before it is
    | converted. Uncalibrated — this is the dial to move once real scores exist.
    |
    */
    'ai_ease_factor' => 0.92,

    /*
    |--------------------------------------------------------------------------
    | Sample thresholds
    |--------------------------------------------------------------------------
    |
    | Questions (words, for vocab) a component needs before it counts as well
    | sampled. Each one met raises the confidence a level. Below `min_total`
    | across all three, or with neither grammar nor reading answered, there is
    | no estimate at all.
    |
    */
    'min_questions' => [
        'part5' => 40,
        'part7' => 20,
        'vocab' => 50,
    ],
    'min_total' => 30,

    /*
    |--------------------------------------------------------------------------
    | Conversion
    |--------------------------------------------------------------------------
    |
    | Reading-section accuracy => scaled score, interpolated linearly between
    | points. Shaped after typical published raw-to-scaled tables; real forms
    | vary by a few tens of points either way.
    |
    */
    'conversion' => [
        '0.0' => 5,
        '0.2' => 60,
        '0.3' => 115,
        '0.4' => 165,
        '0.5' => 215,
        '0.6' => 270,
        '0.7' => 330,
        '0.8' => 385,
        '0.9' => 445,
        '1.0' => 495,
    ],

];
