<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning\Model;

/** What {@see \Smartlabsys\SlsConnectorBundle\Provisioning\TenantProvisionerInterface::create()} returns. */
final class TenantResult
{
    /** @param bool $created false when the org already had a tenant (idempotent create → 200) */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly bool $created,
    ) {}
}
