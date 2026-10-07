<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Provisioning\ClaimCodes;

final class ClaimCodesTest extends TestCase
{
    public function testGeneratesFormattedCodesFromTheAlphabet(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; ++$i) {
            $code = ClaimCodes::generate();
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);
            self::assertTrue(ClaimCodes::isWellFormed(ClaimCodes::normalize($code)));
            $seen[$code] = true;
        }
        self::assertGreaterThan(45, count($seen), 'random');
    }

    public function testNormalize(): void
    {
        self::assertSame('ABCDEFGHJKLM', ClaimCodes::normalize(' abcd-efgh jklm '));
        self::assertSame('ABCD-EFGH-JKLM', ClaimCodes::format('abcd efgh-jklm'));
    }

    public function testIsWellFormed(): void
    {
        self::assertTrue(ClaimCodes::isWellFormed('ABCDEFGHJKLM'));
        self::assertFalse(ClaimCodes::isWellFormed('ABCDEFGHJKL'), 'too short');
        self::assertFalse(ClaimCodes::isWellFormed('ABCDEFGHJKLMN'), 'too long');
        self::assertFalse(ClaimCodes::isWellFormed('ABCDEFGHJKL0'), '0 is not in the alphabet');
        self::assertFalse(ClaimCodes::isWellFormed('ABCD-EFGH-JK'), 'not normalized');
    }

    public function testHashIsSha256OfTheNormalizedCode(): void
    {
        self::assertSame(hash('sha256', 'ABCDEFGHJKLM'), ClaimCodes::hash('abcd-efgh-jklm'));
        self::assertSame(ClaimCodes::hash('ABCD-EFGH-JKLM'), ClaimCodes::hash('abcd efgh jklm'));
    }

    public function testVerify(): void
    {
        $code = ClaimCodes::generate();
        $hash = ClaimCodes::hash($code);

        self::assertTrue(ClaimCodes::verify($code, $hash));
        self::assertTrue(ClaimCodes::verify(strtolower(str_replace('-', ' ', $code)), $hash));
        self::assertFalse(ClaimCodes::verify('ABCD-EFGH-JKLM' === $code ? 'ABCD-EFGH-JKLN' : 'ABCD-EFGH-JKLM', $hash));
        self::assertFalse(ClaimCodes::verify($code, ''), 'no stored hash');
    }

    public function testExpiresAfterThirtyMinutes(): void
    {
        $now = new \DateTimeImmutable('2026-10-07 12:00:00');
        self::assertEquals(new \DateTimeImmutable('2026-10-07 12:30:00'), ClaimCodes::expiresAt($now));
        self::assertSame(1800, ClaimCodes::TTL_SECONDS);
    }
}
