<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning\Model;

final class Tenant
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public function __construct(
        public readonly string $tenantId,
        public readonly string $status = self::STATUS_ACTIVE,
        public readonly ?string $name = null,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter(['tenant_id' => $this->tenantId, 'status' => $this->status, 'name' => $this->name], 'is_string');
    }
}
