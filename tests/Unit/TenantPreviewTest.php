<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\CompanyDetails;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\Tenant;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\TenantPreview;

final class TenantPreviewTest extends TestCase
{
    public function testPlainPreviewsKeepTheirShape(): void
    {
        self::assertSame(['action' => 'create', 'tenant' => null], (new TenantPreview(TenantPreview::ACTION_CREATE))->toArray());
        self::assertSame(
            ['action' => 'claim', 'tenant' => ['tenant_id' => 't1', 'status' => 'active', 'name' => 'Lab']],
            (new TenantPreview(TenantPreview::ACTION_CLAIM, new Tenant('t1', name: 'Lab')))->toArray(),
        );
    }

    public function testClaimByCodeAddsDetailsAndUsers(): void
    {
        $preview = TenantPreview::claimByCode(
            new Tenant('t1', name: 'Lab'),
            new CompanyDetails('Lab', taxId: '101', country: 'RS'),
            [['id' => 'u1', 'email' => 'a@lab.test', 'name' => 'Ana', 'active' => false, 'roles' => ['demo:admin']]],
        );

        self::assertSame([
            'action'  => 'claim',
            'tenant'  => ['tenant_id' => 't1', 'status' => 'active', 'name' => 'Lab'],
            'details' => [
                'name' => 'Lab', 'tax_id' => '101', 'registration_number' => null, 'public_funds_id' => null,
                'address' => null, 'city' => null, 'postal_code' => null, 'country' => 'RS',
            ],
            'users'   => [['id' => 'u1', 'email' => 'a@lab.test', 'name' => 'Ana', 'active' => false, 'roles' => ['demo:admin']]],
        ], $preview->toArray());
    }

    public function testDetailsOnlyForClaims(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TenantPreview(TenantPreview::ACTION_EXISTING, new Tenant('t1'), new CompanyDetails('Lab'));
    }
}
