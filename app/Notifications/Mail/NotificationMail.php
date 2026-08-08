<?php

namespace App\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * One notification, as an email.
 *
 * THE TITLE AND LINK COME FROM THE FEED'S OWN HYDRATION, passed in rather than
 * re-derived. If this rendered its own subject line, the email and the in-app row
 * would describe one event in two different ways — and the first person to notice
 * would be a parent reading both.
 *
 * ⚠️ THE SES CONFIGURATION SET IS ATTACHED AS A HEADER, and it is what makes Part B
 * work at all. SES publishes bounce and complaint events to SNS only for messages
 * sent under a configuration set carrying an event destination. Omit the header and
 * mail still sends perfectly — it simply generates no events, so the suppression
 * table stays empty and "safe channel" degrades to send-only. That is the failure
 * that looks exactly like success.
 *
 * Null when unconfigured, so a deployment without the SES pipeline sends without a
 * meaningless header rather than refusing.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $deepLink = null,
        private readonly ?string $configurationSet = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function headers(): Headers
    {
        return new Headers(
            text: $this->configurationSet !== null && $this->configurationSet !== ''
                ? ['X-SES-CONFIGURATION-SET' => $this->configurationSet]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'notifications.generic', with: [
            'title' => $this->title,
            'body' => $this->body,
            'deepLink' => $this->deepLink,
        ]);
    }
}
