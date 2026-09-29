<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning\Model;

/**
 * What {@see \Smartlabsys\SlsConnectorBundle\Provisioning\TenantPreviewInterface::preview()} returns:
 * what creating the tenant would do right now, without doing it.
 */
final class TenantPreview
{
    /** The org already has a tenant — create would return it. */
    public const ACTION_EXISTING = 'existing';
    /** An existing, unlinked tenant owned by the claim e-mail would be linked. */
    public const ACTION_CLAIM = 'claim';
    /** A new tenant would be created. */
    public const ACTION_CREATE = 'create';

    /** @param Tenant|null $tenant the tenant that would be returned or linked; null for ACTION_CREATE */
    public function __construct(
        public readonly string $action,
        public readonly ?Tenant $tenant = null,
    ) {
        if (!in_array($action, [self::ACTION_EXISTING, self::ACTION_CLAIM, self::ACTION_CREATE], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown tenant preview action "%s".', $action));
        }
        if (($tenant === null) !== ($action === self::ACTION_CREATE)) {
            throw new \InvalidArgumentException('A tenant is required for "existing" and "claim", and not allowed for "create".');
        }
    }

    /** @return array{action: string, tenant: array<string, string>|null} */
    public function toArray(): array
    {
        return ['action' => $this->action, 'tenant' => $this->tenant?->toArray()];
    }
}
