<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning;

use Smartlabsys\SlsConnectorBundle\Provisioning\Model\Tenant;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\TenantRequest;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\TenantResult;

/**
 * Implemented by the app: creates and manages the tenant an SLS organization gets in this app
 * (doc 05 §3). A dedicated single-tenant install returns its one fixed tenant.
 */
interface TenantProvisionerInterface
{
    /**
     * Idempotent on `slsCompanyId` (contract 2), or on `slsOrganizationId` when the request has no
     * company (a contract 1 platform). Lookup order with a company:
     *  1. the tenant linked to `slsCompanyId` (`created` false);
     *  2. a tenant linked to `slsOrganizationId` but to no company yet, made before contract 2: link
     *     it to `slsCompanyId` now (`created` false);
     *  3. when `claimOwnerEmail` is set, an existing tenant linked to no SLS org where a user with
     *     that e-mail is an owner (`created` true), instead of a duplicate;
     *  4. a new tenant from `company` (`created` true).
     * Several companies of one org get separate tenants.
     */
    public function create(TenantRequest $request): TenantResult;

    public function get(string $tenantId): ?Tenant;

    /** Users of a suspended tenant can't sign in; data is kept. Returns null for an unknown tenant. */
    public function suspend(string $tenantId): ?Tenant;

    public function resume(string $tenantId): ?Tenant;

    /** Only on an explicit Owner request in SLS. Returns false for an unknown tenant. */
    public function delete(string $tenantId): bool;
}
