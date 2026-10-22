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
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'base_url' => 'https://api.anthropic.com/v1/messages',
        'version' => '2023-06-01',
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
