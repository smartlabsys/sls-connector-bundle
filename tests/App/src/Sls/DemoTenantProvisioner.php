<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\Tenant;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\TenantPreview;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\TenantRequest;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\TenantResult;
use Smartlabsys\SlsConnectorBundle\Provisioning\TenantPreviewInterface;
use Smartlabsys\SlsConnectorBundle\Provisioning\TenantProvisionerInterface;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;

final class DemoTenantProvisioner implements TenantProvisionerInterface, TenantPreviewInterface
{
    public function __construct(private JsonStore $store) {}

    public function create(TenantRequest $request): TenantResult
    {
        return $this->store->update(static function (array &$data) use ($request): TenantResult {
            $found = self::find($data['tenants'] ?? [], $request);
            if ($found !== null) {
                // A contract 1 tenant moves onto the first company that asks for it.
                $data['tenants'][$found]['sls_company_id'] ??= $request->slsCompanyId;

                return new TenantResult(self::tenant($data['tenants'][$found]), false);
            }
            self::rejectClaimCode($request);
            $id                   = 'tn_' . JsonStore::id();
            $data['tenants'][$id] = [
                'tenant_id'      => $id,
                'sls_org_id'     => $request->slsOrganizationId,
                'sls_company_id' => $request->slsCompanyId,
                'name'           => $request->company->name ?? $request->name,
                'status'         => Tenant::STATUS_ACTIVE,
            ];

            return new TenantResult(self::tenant($data['tenants'][$id]), true);
        });
    }

    public function preview(TenantRequest $request): TenantPreview
    {
        $tenants = $this->store->read()['tenants'] ?? [];
        $found   = self::find($tenants, $request);
        if ($found === null) {
            self::rejectClaimCode($request);
        }

        return $found !== null
            ? new TenantPreview(TenantPreview::ACTION_EXISTING, self::tenant($tenants[$found]))
            : new TenantPreview(TenantPreview::ACTION_CREATE);
    }

    /**
     * The tenant id for this request (TenantProvisionerInterface::create() lookup order, steps 1–2):
     * by company, else the org's tenant that has no company yet; contract 1 requests by org.
     *
     * @param array<string, array<string, ?string>> $tenants
     */
    private static function find(array $tenants, TenantRequest $request): ?string
    {
        if ($request->slsCompanyId === null) {
            foreach ($tenants as $id => $row) {
                if ($row['sls_org_id'] === $request->slsOrganizationId) {
                    return $id;
                }
            }

            return null;
        }
        foreach ($tenants as $id => $row) {
            if (($row['sls_company_id'] ?? null) === $request->slsCompanyId) {
                return $id;
            }
        }
        foreach ($tenants as $id => $row) {
            if ($row['sls_org_id'] === $request->slsOrganizationId && ($row['sls_company_id'] ?? null) === null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Step 3 (claim by code): the demo has no standalone companies, so no claim code is ever valid.
     * A real app looks the code up with ClaimCodes::hash() and links that company instead.
     */
    private static function rejectClaimCode(TenantRequest $request): void
    {
        if ($request->claimCode !== null) {
            throw ContractException::invalidClaimCode();
        }
    }

    public function get(string $tenantId): ?Tenant
    {
        $row = $this->store->read()['tenants'][$tenantId] ?? null;

        return $row !== null ? self::tenant($row) : null;
    }

    public function suspend(string $tenantId): ?Tenant
    {
        return $this->setStatus($tenantId, Tenant::STATUS_SUSPENDED);
    }

    public function resume(string $tenantId): ?Tenant
    {
        return $this->setStatus($tenantId, Tenant::STATUS_ACTIVE);
    }

    public function delete(string $tenantId): bool
    {
        return $this->store->update(static function (array &$data) use ($tenantId): bool {
            if (!isset($data['tenants'][$tenantId])) {
                return false;
            }
            unset($data['tenants'][$tenantId], $data['scim_users'][$tenantId], $data['scim_groups'][$tenantId], $data['seeds'][$tenantId]);

            return true;
        });
    }

    private function setStatus(string $tenantId, string $status): ?Tenant
    {
        return $this->store->update(static function (array &$data) use ($tenantId, $status): ?Tenant {
            if (!isset($data['tenants'][$tenantId])) {
                return null;
            }
            $data['tenants'][$tenantId]['status'] = $status;

            return self::tenant($data['tenants'][$tenantId]);
        });
    }

    /** @param array<string, ?string> $row */
    private static function tenant(array $row): Tenant
    {
        return new Tenant($row['tenant_id'], $row['status'], $row['name']);
    }
}
