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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sslcommerz' => [
        'store_id' => env('SSLCOMMERZ_STORE_ID', 'krishihat_sandbox'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD', 'krishihat_secret@123'),
        'sandbox' => env('SSLCOMMERZ_IS_SANDBOX', true),
        'api_domain' => env('SSLCOMMERZ_IS_SANDBOX', true)
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | KrishiHat AI Chatbot (free providers)
    |--------------------------------------------------------------------------
    | CHATBOT_PROVIDER: gemini | groq | openrouter
    | Without an API key the chatbot still works using built-in rule-based answers.
    */
    'chatbot' => [
        'provider' => env('CHATBOT_PROVIDER', 'gemini'),
        'timeout' => (int) env('CHATBOT_TIMEOUT', 25),

        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
        ],

        'groq' => [
            'key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        ],

        'openrouter' => [
            'key' => env('OPENROUTER_API_KEY'),
            'model' => env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        ],
    ],

];
