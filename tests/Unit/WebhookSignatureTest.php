<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Webhook\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    public function testSignsTimestampDotBody(): void
    {
        self::assertSame(
            'sha256=' . hash_hmac('sha256', '1700000000.{"a":1}', 's3cret'),
            WebhookSignature::sign('s3cret', 1700000000, '{"a":1}'),
        );
    }

    public function testVerify(): void
    {
        $now = 1700000000;
        $sig = WebhookSignature::sign('s3cret', $now, 'body');

        self::assertTrue(WebhookSignature::verify('s3cret', (string) $now, $sig, 'body', $now));
        self::assertTrue(WebhookSignature::verify('s3cret', (string) $now, strtoupper($sig), 'body', $now + 300));
        self::assertFalse(WebhookSignature::verify('s3cret', (string) $now, $sig, 'body', $now + 301), 'stale');
        self::assertFalse(WebhookSignature::verify('s3cret', (string) $now, $sig, 'body', $now - 301), 'future');
        self::assertFalse(WebhookSignature::verify('other', (string) $now, $sig, 'body', $now), 'wrong secret');
        self::assertFalse(WebhookSignature::verify('s3cret', (string) $now, $sig, 'body2', $now), 'tampered');
        self::assertFalse(WebhookSignature::verify('s3cret', null, $sig, 'body', $now), 'no timestamp');
        self::assertFalse(WebhookSignature::verify('s3cret', (string) $now, null, 'body', $now), 'no signature');
        self::assertFalse(WebhookSignature::verify('s3cret', '17e8', $sig, 'body', $now), 'non-numeric');
    }
}
