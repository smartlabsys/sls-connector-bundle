<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Provisioning\ClaimHandback;

final class ClaimHandbackTest extends TestCase
{
    public function testPostsCodeAndStateWithoutPuttingThemInAUrl(): void
    {
        $response = ClaimHandback::response('https://sls.test/connections/claim/callback', 'ABCD-EFGH-JKLM', 'st"ate<x>', 'Nastavi', 'Povratak na SLS', 'sr');
        $html     = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<form id="sls-claim-handback" method="post" action="https://sls.test/connections/claim/callback">', $html);
        self::assertStringContainsString('<input type="hidden" name="code" value="ABCD-EFGH-JKLM">', $html);
        self::assertStringContainsString('<input type="hidden" name="state" value="st&quot;ate&lt;x&gt;">', $html, 'escaped');
        self::assertStringContainsString('<button type="submit">Nastavi</button>', $html, 'works without scripts');
        self::assertStringContainsString('<html lang="sr">', $html);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString('form-action https://sls.test;', $csp);
        preg_match('#<script>(.*)</script>#', $html, $script);
        self::assertStringContainsString("script-src 'sha256-" . base64_encode(hash('sha256', $script[1], true)) . "'", $csp, 'the inline script is allowed by its hash');
    }

    public function testNoStateNoStateField(): void
    {
        $html = (string) ClaimHandback::response('http://127.0.0.1:8002/connections/claim/callback?x=1', 'CODE', null)->getContent();

        self::assertStringNotContainsString('name="state"', $html);
        self::assertStringContainsString('action="http://127.0.0.1:8002/connections/claim/callback?x=1"', $html);
    }

    public function testRejectsARelativeReturnTo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClaimHandback::response('/connections/claim/callback', 'CODE', null);
    }

    public function testIsOnIssuer(): void
    {
        self::assertTrue(ClaimHandback::isOnIssuer('https://sls.test/connections/claim/callback', 'https://sls.test/'));
        self::assertTrue(ClaimHandback::isOnIssuer('https://sls.test', 'https://sls.test'));
        self::assertTrue(ClaimHandback::isOnIssuer('https://sls.test?x=1', 'https://sls.test'));
        self::assertFalse(ClaimHandback::isOnIssuer('https://sls.test.evil/connections', 'https://sls.test'));
        self::assertFalse(ClaimHandback::isOnIssuer('https://sls.test@evil/x', 'https://sls.test'));
        self::assertFalse(ClaimHandback::isOnIssuer('https://sls.test/x#frag', 'https://sls.test'));
        self::assertFalse(ClaimHandback::isOnIssuer('https://evil/', ''));
    }
}
