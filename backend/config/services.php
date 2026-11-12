<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // used by every call whose purpose is not listed under 'tasks'
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'base_url' => 'https://api.anthropic.com/v1/messages',
        'version' => '2023-06-01',

        // Per-purpose model and effort (the purpose names are the third
        // argument to ClaudeService::callJson). Quiz generation is templated
        // JSON work that does not need the top model; the post-quiz review is
        // a short summary and runs on the smallest one. Effort is only sent
        // when set, so leave it empty for models that do not accept it.
        'tasks' => [
            'quiz' => [
                'model' => env('ANTHROPIC_MODEL_QUIZ', 'claude-sonnet-5-5'),
                'effort' => env('ANTHROPIC_EFFORT_QUIZ', 'medium'),
            ],
            'quiz_news' => [
                'model' => env('ANTHROPIC_MODEL_QUIZ', 'claude-sonnet-5-5'),
                'effort' => env('ANTHROPIC_EFFORT_QUIZ', 'medium'),
            ],
            'quiz_new_words' => [
                'model' => env('ANTHROPIC_MODEL_QUIZ', 'claude-sonnet-5-5'),
                'effort' => env('ANTHROPIC_EFFORT_QUIZ', 'medium'),
            ],
            'quiz_review' => [
                'model' => env('ANTHROPIC_MODEL_REVIEW', 'claude-haiku-5-5'),
                'effort' => env('ANTHROPIC_EFFORT_REVIEW', 'low'),
            ],
        ],

        // USD per million tokens [input, output], for `php artisan ai:usage`
        // estimates only. Unknown models are reported without a cost.
        'prices' => [
            'claude-opus-4-8' => [5.00, 25.00],
            'claude-opus-5-5' => [4.00, 20.00],
            'claude-sonnet-4-6' => [3.00, 15.00],
            'claude-sonnet-5-5' => [2.00, 10.00],
            'claude-haiku-4-5' => [1.00, 5.00],
            'claude-haiku-5-5' => [0.10, 0.50],
        ],
    ],

    'dictionary' => [
        'url' => env('DICTIONARY_API_URL', 'https://api.dictionaryapi.dev/api/v2/entries/en'),
    ],

    'news' => [
        // RSS feeds keyed by topic. No API key required. Ordered most to least
        // TOEIC-like: Part 7 reads business documents, so corporate press
        // releases sit closest to the exam and general news furthest. The first
        // entry is the default when a request names an unknown topic.
        //
        // Only add a feed whose article pages survive NewsService's paragraph
        // scrape — NPR and Reuters were tried and dropped (NPR's markup yields
        // no paragraphs, Reuters' public RSS is gone), and a feed that scrapes
        // to nothing degrades silently to the much shorter RSS summary.
        'feeds' => [
            'press_release' => env('NEWS_FEED_PRESS_RELEASE', 'https://www.prnewswire.com/rss/business-technology-latest-news/business-technology-latest-news-list.rss'),
            'us_business' => env('NEWS_FEED_US_BUSINESS', 'https://www.cnbc.com/id/10001147/device/rss/rss.html'),
            'business' => env('NEWS_FEED_BUSINESS', 'https://feeds.bbci.co.uk/news/business/rss.xml'),
            'technology' => env('NEWS_FEED_TECHNOLOGY', 'https://feeds.bbci.co.uk/news/technology/rss.xml'),
        ],
        // Some sites reject requests without a browser-like User-Agent.
        'user_agent' => env('NEWS_USER_AGENT', 'Mozilla/5.0 (compatible; ToeicLearningBot/1.0)'),
    ],

];
