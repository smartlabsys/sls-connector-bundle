<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

/**
 * Optional (0.3): turns an SLS `tenant_id` into the app's tenant object (a `Company`, …), for
 * controllers marked `#[SlsSibling]`. Return null when the tenant doesn't exist here (the request
 * is then answered 404 `tenant_not_found`). Implement it once; the bundle aliases it.
 */
interface SlsTenantResolverInterface
{
    public function resolveTenant(string $tenantId): ?object;
}
