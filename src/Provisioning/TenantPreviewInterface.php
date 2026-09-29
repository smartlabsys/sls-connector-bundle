<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning;

use Smartlabsys\SlsConnectorBundle\Provisioning\Model\TenantPreview;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\TenantRequest;

/**
 * Optional, next to {@see TenantProvisionerInterface}: answers `POST /tenants/preview` (doc 05 §3),
 * which SLS's connect wizard calls to tell the user whether an existing tenant would be reused or
 * claimed. Without it the endpoint answers 501 and SLS shows a neutral message.
 */
interface TenantPreviewInterface
{
    /**
     * Must decide exactly as {@see TenantProvisionerInterface::create()} would for the same request,
     * and must not write anything.
     */
    public function preview(TenantRequest $request): TenantPreview;
}
