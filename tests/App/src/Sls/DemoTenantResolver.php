<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Provisioning\Model\Tenant;
use Smartlabsys\SlsConnectorBundle\Security\SlsTenantResolverInterface;

/** The tenant a sibling's token names, for `#[SlsSibling]` endpoints: active ones only. */
final class DemoTenantResolver implements SlsTenantResolverInterface
{
    public function __construct(private DemoTenantProvisioner $tenants) {}

    public function resolveTenant(string $tenantId): ?Tenant
    {
        $tenant = $this->tenants->get($tenantId);

        return $tenant?->status === Tenant::STATUS_ACTIVE ? $tenant : null;
    }
}
