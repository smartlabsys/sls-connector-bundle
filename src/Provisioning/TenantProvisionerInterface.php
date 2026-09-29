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
     * Idempotent on `slsOrganizationId`: an org that already has a tenant gets it back (`created` false).
     * Otherwise, when `claimOwnerEmail` is set, the app may link an existing tenant that is not yet
     * linked to any SLS org and where a user with that e-mail is an owner (`created` true), instead
     * of creating a duplicate.
     */
    public function create(TenantRequest $request): TenantResult;

    public function get(string $tenantId): ?Tenant;

    /** Users of a suspended tenant can't sign in; data is kept. Returns null for an unknown tenant. */
    public function suspend(string $tenantId): ?Tenant;

    public function resume(string $tenantId): ?Tenant;

    /** Only on an explicit Owner request in SLS. Returns false for an unknown tenant. */
    public function delete(string $tenantId): bool;
}
