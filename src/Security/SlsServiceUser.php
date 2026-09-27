<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * SLS itself, calling this app with a service token (doc 05, "SLS → app"). Never stored; built
 * from the validated token's claims on every request.
 */
final class SlsServiceUser implements UserInterface
{
    public const ROLE = 'ROLE_SLS_SERVICE';

    /** @var string[] */
    public readonly array $scopes;

    public readonly ?string $tenantId;

    /** @param array<string, mixed> $claims */
    public function __construct(public readonly array $claims)
    {
        $this->scopes   = array_values(array_filter(explode(' ', (string) ($claims['scope'] ?? ''))));
        $this->tenantId = isset($claims['tenant_id']) && is_string($claims['tenant_id']) && $claims['tenant_id'] !== ''
            ? $claims['tenant_id']
            : null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->claims['sub'];
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
    }
}
