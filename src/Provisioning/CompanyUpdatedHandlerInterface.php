<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning;

use Smartlabsys\SlsConnectorBundle\Provisioning\Model\CompanyDetails;

/**
 * Optional (0.3): keeps the app's copy of a connected company's details in step with SLS. The
 * webhook receiver calls it on `company.updated` with the tenant and the full new details, after
 * dispatching the raw `sls.webhook.company.updated` event. Implement it once; the bundle aliases it.
 * Throwing makes the receiver answer 500, so SLS retries.
 */
interface CompanyUpdatedHandlerInterface
{
    public function companyUpdated(string $tenantId, CompanyDetails $company): void;
}
