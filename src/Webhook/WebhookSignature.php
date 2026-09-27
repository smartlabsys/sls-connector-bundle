<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Webhook;

/**
 * SLS webhook signatures (doc 05 §5, doc 09):
 * `X-SLS-Signature: sha256=<hex HMAC-SHA256(SLS_WEBHOOK_SECRET, "{X-SLS-Timestamp}.{raw body}")>`,
 * with `X-SLS-Timestamp` (Unix seconds) at most 5 minutes off.
 */
final class WebhookSignature
{
    public const SIGNATURE_HEADER = 'X-SLS-Signature';
    public const TIMESTAMP_HEADER = 'X-SLS-Timestamp';
    public const TOLERANCE        = 300;

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** True when the signature is valid and the timestamp fresh. */
    public static function verify(string $secret, ?string $timestamp, ?string $signature, string $body, ?int $now = null): bool
    {
        if ($timestamp === null || $signature === null || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        return hash_equals(self::sign($secret, (int) $timestamp, $body), strtolower(trim($signature)));
    }
}
