<?php

namespace App\Notifications\Services;

use RuntimeException;

/**
 * HMAC-SHA256 over the callback body, for the receiving service to verify.
 *
 * THE TIMESTAMP IS INSIDE THE SIGNED STRING, not merely sent alongside it. Signing
 * the body alone produces a signature that stays valid forever, so a captured request
 * can be replayed to revoke a pickup a week later. Binding the timestamp into the
 * signed material is what lets the receiver reject anything outside its freshness
 * window — the signature and the age become one claim rather than two.
 *
 * THE SECRET IS REQUIRED, LOUDLY. A signer that falls back to an empty key produces
 * signatures that verify against a known value, which is worse than no signing at all
 * because the receiver believes it authenticated. Misconfiguration must stop the
 * request, not silently downgrade it.
 */
final class ServiceCallbackSigner
{
    public function __construct(private readonly ?string $secret)
    {
        if ($this->secret === null || $this->secret === '') {
            throw new RuntimeException(
                'Callback signing secret is not configured '
                .'(services.pickup_authorization.callback_secret). Refusing to sign with an '
                .'empty key — a signature over a known secret authenticates nothing while '
                .'appearing to.'
            );
        }
    }

    /**
     * @return array{signature: string, timestamp: int}
     */
    public function sign(string $body, ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();

        return [
            'signature' => hash_hmac('sha256', $timestamp.'.'.$body, (string) $this->secret),
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Constant-time comparison, for any inbound verification that reuses this.
     *
     * `hash_equals`, never `===`: a short-circuiting string comparison leaks the
     * position of the first differing byte through timing, which is enough to
     * reconstruct a signature one byte at a time.
     */
    public function verify(string $body, int $timestamp, string $signature): bool
    {
        $expected = $this->sign($body, $timestamp)['signature'];

        return hash_equals($expected, $signature);
    }
}
