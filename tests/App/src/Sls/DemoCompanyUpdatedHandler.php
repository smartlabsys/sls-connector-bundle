<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Provisioning\CompanyUpdatedHandlerInterface;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\CompanyDetails;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;

/** Copies the SLS company's details onto the tenant (`company.updated`, contract 2). */
final class DemoCompanyUpdatedHandler implements CompanyUpdatedHandlerInterface
{
    public function __construct(private JsonStore $store) {}

    public function companyUpdated(string $tenantId, CompanyDetails $company): void
    {
        $this->store->update(static function (array &$data) use ($tenantId, $company): void {
            if (!isset($data['tenants'][$tenantId])) {
                return;
            }
            $data['tenants'][$tenantId]['name']    = $company->name;
            $data['tenants'][$tenantId]['company'] = $company->toArray();
        });
    }
}
