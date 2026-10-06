<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A sibling app calling this app as itself (doc 09, client credentials with `resource`): the token's
 * `sub` is the caller's OAuth client, `app` / `instance_id` name it, `tenant_id` is the tenant
 * here it may act in and `callerTenantId` the caller's own tenant the call comes from (links, 10.4); `partnershipId` / `partnershipRole` are set when it comes
 * over a partnership, possibly from another organization (10.9). Only accepted with `sls_connector.api.accept_app_tokens: true`. Never stored.
 */
final class SlsAppUser implements UserInterface
{
    public const ROLE = 'ROLE_SLS_APP';

    /** @var string[] */
    public readonly array $scopes;

    public readonly string $clientId;

    public readonly ?string $app;

    public readonly ?string $instanceId;

    public readonly ?string $organizationId;

    public readonly ?string $tenantId;

    public readonly ?string $callerTenantId;

    /** Set when the call goes over a partnership (doc 09 §2b) rather than an app link. */
    public readonly ?string $partnershipId;

    /** The partnership role (`qc:laboratory`) when {@see $partnershipId} is set. */
    public readonly ?string $partnershipRole;

    /** @param array<string, mixed> $claims */
    public function __construct(public readonly array $claims)
    {
        $string = static fn (string $key): ?string => isset($claims[$key]) && is_string($claims[$key]) && $claims[$key] !== ''
            ? $claims[$key]
            : null;

        $this->scopes         = array_values(array_filter(explode(' ', (string) ($claims['scope'] ?? ''))));
        $this->clientId       = (string) $claims['sub'];
        $this->app            = $string('app');
        $this->instanceId     = $string('instance_id');
        $this->organizationId = $string('org_id');
        $this->tenantId       = $string('tenant_id');
        $this->callerTenantId = $string('caller_tenant_id');
        $this->partnershipId  = $string('partnership_id');
        $this->partnershipRole = $this->partnershipId !== null ? $string('role') : null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function getUserIdentifier(): string
    {
        return $this->clientId;
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
    }
}
