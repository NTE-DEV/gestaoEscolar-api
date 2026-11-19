<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'portal_semed' => [
        'base_url' => env('PORTAL_SEMED_API_URL', 'https://portalsemed.vilavelha.es.gov.br'),
        'timeout' => (int) env('PORTAL_SEMED_TIMEOUT', 15),
        'api_token' => env('PORTAL_API_TOKEN'),
        'group_auto_sync' => filter_var(env('PORTAL_GROUP_AUTO_SYNC', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'docker' => [
        'host_http' => env('DOCKER_HOST_HTTP'),
        'socket_path' => env('DOCKER_SOCKET_PATH', '/var/run/docker.sock'),
    ],

];
