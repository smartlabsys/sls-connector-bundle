<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning\Model;

/**
 * `POST /sls/provisioning/tenants` body: create (or return) the tenant of an SLS company (contract 2),
 * or of an SLS organization from a contract 1 platform, which sends no `sls_company_id`.
 * `claimOwnerEmail` is the connecting user's verified e-mail (`claim.owner_email`), if SLS sent one.
 */
final class TenantRequest
{
    /** @param array<string, mixed> $payload the full request body */
    public function __construct(
        public readonly string $slsOrganizationId,
        public readonly string $name,
        public readonly ?string $slug,
        public readonly ?string $locale,
        public readonly array $payload = [],
        public readonly ?string $claimOwnerEmail = null,
        /** Contract 2: the SLS company this tenant belongs to; one org can have several. */
        public readonly ?string $slsCompanyId = null,
        public readonly ?CompanyDetails $company = null,
    ) {}
}
