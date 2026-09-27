<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A sibling app calling this app as itself (doc 09, client credentials with `resource`): the token's
 * `sub` is the caller's OAuth client, `app` / `instance_id` name it, and `tenant_id` is the tenant
 * here it may act in. Only accepted with `sls_connector.api.accept_app_tokens: true`. Never stored.
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
