<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning\Model;

/** `POST /sls/provisioning/tenants/{tenantId}/seeds` body (doc 08). */
final class SeedRequest
{
    /**
     * @param array<string, mixed> $parameters template parameters chosen by the admin
     * @param array<string, mixed> $context    SLS org context: `org` {name, locale}, `users` [{slsUserId, roles}]
     */
    public function __construct(
        public readonly string $template,
        public readonly int $version,
        public readonly array $parameters,
        public readonly array $context,
        public readonly ?string $idempotencyKey,
    ) {}
}
