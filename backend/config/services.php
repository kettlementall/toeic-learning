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
        // BBC RSS feeds keyed by topic. No API key required.
        'feeds' => [
            'top' => env('NEWS_FEED_TOP', 'https://feeds.bbci.co.uk/news/rss.xml'),
            'world' => env('NEWS_FEED_WORLD', 'https://feeds.bbci.co.uk/news/world/rss.xml'),
            'business' => env('NEWS_FEED_BUSINESS', 'https://feeds.bbci.co.uk/news/business/rss.xml'),
            'technology' => env('NEWS_FEED_TECHNOLOGY', 'https://feeds.bbci.co.uk/news/technology/rss.xml'),
        ],
        // Some sites reject requests without a browser-like User-Agent.
        'user_agent' => env('NEWS_USER_AGENT', 'Mozilla/5.0 (compatible; ToeicLearningBot/1.0)'),
    ],

];
