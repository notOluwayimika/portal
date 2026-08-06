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
        // ⚠️ REQUIRED FOR BOUNCE HANDLING, not for sending. SES emits bounce and
        // complaint events to SNS only for messages sent under a configuration set
        // that has an event destination. Unset, mail sends perfectly and generates
        // NO events — so the suppression table stays empty and the channel is
        // send-only wearing a safety label.
        'configuration_set' => env('AWS_SES_CONFIGURATION_SET'),
        // ⚠️ THE SNS ENDPOINT'S REAL SECURITY BOUNDARY, alongside the signature.
        // MessageValidator proves a message was signed by AWS SNS — it does NOT prove
        // it came from OUR topic. Anyone with an AWS account can create a topic and
        // send a genuinely-signed message to a public endpoint. Unset, the handler
        // FAILS CLOSED rather than trusting any signed message.
        'sns_topic_arn' => env('AWS_SES_SNS_TOPIC_ARN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * The service that owns the pickup decision. The secret signs outbound callbacks;
     * ServiceCallbackSigner REFUSES to construct without it rather than falling back
     * to an empty key, because a signature over a known secret authenticates nothing
     * while appearing to.
     */
    'pickup_authorization' => [
        'callback_secret' => env('PICKUP_AUTHORIZATION_CALLBACK_SECRET'),
    ],

];
