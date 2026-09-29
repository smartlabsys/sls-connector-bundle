<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

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
            foreach ($data['tenants'] ?? [] as $row) {
                if ($row['sls_org_id'] === $request->slsOrganizationId) {
                    return new TenantResult(self::tenant($row), false);
                }
            }
            $id                   = 'tn_' . JsonStore::id();
            $data['tenants'][$id] = ['tenant_id' => $id, 'sls_org_id' => $request->slsOrganizationId, 'name' => $request->name, 'status' => Tenant::STATUS_ACTIVE];

            return new TenantResult(self::tenant($data['tenants'][$id]), true);
        });
    }

    public function preview(TenantRequest $request): TenantPreview
    {
        foreach ($this->store->read()['tenants'] ?? [] as $row) {
            if ($row['sls_org_id'] === $request->slsOrganizationId) {
                return new TenantPreview(TenantPreview::ACTION_EXISTING, self::tenant($row));
            }
        }

        return new TenantPreview(TenantPreview::ACTION_CREATE);
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

    /** @param array<string, string> $row */
    private static function tenant(array $row): Tenant
    {
        return new Tenant($row['tenant_id'], $row['status'], $row['name']);
    }
}
