<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\CompanyDetails;

final class CompanyDetailsTest extends TestCase
{
    public function testNeedsAName(): void
    {
        self::assertNull(CompanyDetails::fromArray(['tax_id' => '101']));
        self::assertNull(CompanyDetails::fromArray(['name' => '  ']));
    }

    public function testTellsALeftOutKeyFromABlankOne(): void
    {
        $details = CompanyDetails::fromArray(['name' => ' Lab A ', 'tax_id' => '', 'city' => 'Novi Sad', 'unknown' => 'x']);

        self::assertSame('Lab A', $details->name);
        self::assertNull($details->taxId);
        self::assertTrue($details->wasSent('tax_id'), 'sent blank');
        self::assertFalse($details->wasSent('address'), 'left out');
        self::assertFalse($details->wasSent('unknown'));
        self::assertSame(['name' => 'Lab A', 'tax_id' => null, 'city' => 'Novi Sad'], $details->toArray(onlySent: true));
        self::assertCount(8, $details->toArray(), 'every key by default');
    }

    public function testBuiltInCodeHasEveryKey(): void
    {
        $details = new CompanyDetails('Lab A', city: 'Novi Sad');

        self::assertTrue($details->wasSent('address'));
        self::assertSame($details->toArray(), $details->toArray(onlySent: true));
    }
}
