<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media root
    |--------------------------------------------------------------------------
    |
    | Set files (*.json), audio and Part 1 photos live here, outside version
    | control: the course material is licensed for the learner's own study, so
    | it stays on the server and in ~/toeic-backups, never in the repository.
    | Paths inside set files are relative to this directory.
    |
    */
    'root' => storage_path('app/private/practice'),

    /*
    |--------------------------------------------------------------------------
    | Reading mock timer
    |--------------------------------------------------------------------------
    |
    | Default for a reading set that does not state its own limit. A 46-item
    | Part 5+6 block at exam pace (about 20s per Part 5 item, a minute per
    | Part 6 passage blank) is roughly 18 minutes.
    |
    */
    'default_reading_seconds' => 1080,

    /*
    |--------------------------------------------------------------------------
    | Listening mock: answer window
    |--------------------------------------------------------------------------
    |
    | After a group's audio ends, how long the learner gets before the next
    | group starts. The real test leaves about 5s per Part 1/2 item and 8s per
    | Part 3/4 question.
    |
    */
    'answer_gap_seconds' => [1 => 5, 2 => 5, 3 => 8, 4 => 8],

    /*
    |--------------------------------------------------------------------------
    | Retry size
    |--------------------------------------------------------------------------
    */
    'retry_max' => 30,

    /*
    |--------------------------------------------------------------------------
    | Skills
    |--------------------------------------------------------------------------
    |
    | What each question tests. Part 5 reuses the app's ten grammar-point codes
    | (QuizBuilderService::GRAMMAR_POINTS) so the labels read the same, but the
    | practice bank never writes review_logs — these drive its own stats only.
    |
    */
    'skills' => [
        // Part 5 (shared codes)
        'word_form' => '詞性',
        'tense_voice' => '時態與語態',
        'preposition' => '介系詞',
        'conjunction' => '連接詞',
        'pronoun' => '代名詞',
        'relative' => '關係詞',
        'comparison' => '比較級',
        'agreement' => '主謂一致',
        'collocation' => '慣用搭配',
        'vocab_in_context' => '語境詞彙',
        // Part 5 extras the course drills
        'verbal' => '不定詞／動名詞／分詞',
        // Part 6
        'sentence_insertion' => '插入句',
        'connective' => '連接副詞',
        // Part 7
        'main_idea' => '主旨目的',
        'detail' => '細節',
        'not_true' => '是非題',
        'inference' => '推論',
        'synonym' => '同義字',
        'intent' => '意圖',
        'position' => '句子插入位置',
        'cross_reference' => '多篇整合',
        // Listening
        'photo' => '照片描述',
        'wh_question' => 'Wh 問句',
        'yes_no' => '是非問句',
        'other_question' => '其他題型',
        'speaker_place' => '說話者／地點',
        'problem' => '問題點',
        'request_next' => '要求／建議／下一步',
        'graphic' => '圖表題',
    ],

];
