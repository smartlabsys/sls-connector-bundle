<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning\Model;

/** `POST /sls/provisioning/tenants` body: create (or return) the tenant of an SLS organization. */
final class TenantRequest
{
    /** @param array<string, mixed> $payload the full request body */
    public function __construct(
        public readonly string $slsOrganizationId,
        public readonly string $name,
        public readonly ?string $slug,
        public readonly ?string $locale,
        public readonly array $payload = [],
    ) {}
}
