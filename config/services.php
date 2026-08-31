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
    /*
     * PAYSTACK — the payment gateway (§6 steps 3-6).
     *
     * THE SECRET KEY IS THE WEBHOOK VERIFICATION KEY TOO. Paystack signs every webhook with an
     * HMAC-SHA512 over the raw body keyed on this same secret, so this one value is both the
     * outbound API credential and the inbound authenticity check. Leaking it does not merely let
     * someone call the API as us — it lets them forge webhooks we would believe.
     *
     * NO DEFAULTS, DELIBERATELY. `env('...')` with no second argument yields null when unset, and
     * PaystackSignature refuses to verify with an empty key rather than computing an HMAC over ''.
     * A signature checked against a known-empty secret authenticates nothing while appearing to —
     * the same reasoning as services.pickup_authorization.callback_secret.
     */
    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        // Overridable so a test or a sandbox can point elsewhere; the default is production's host.
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    'pickup_authorization' => [
        'callback_secret' => env('PICKUP_AUTHORIZATION_CALLBACK_SECRET'),
    ],

];
