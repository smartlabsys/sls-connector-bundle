<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Every SLS → app endpoint of the bundle calls this first. It uses the {@see SlsServiceUser} the
 * firewall authenticated, or — when the app has no firewall in front of the endpoint — validates
 * the bearer token itself, so a misconfigured firewall never leaves an endpoint open. Then it
 * checks the scope and, for tenant-scoped calls, that the token's `tenant_id` matches.
 */
final class ServiceRequestGuard
{
    public function __construct(
        private SlsServiceTokenHandler $tokenHandler,
        private ?Security $security = null,
    ) {}

    /** @throws ContractException 401 / 403 */
    public function require(Request $request, string $scope, ?string $tenantId = null): SlsServiceUser
    {
        $user = $this->security?->getUser();
        if (!$user instanceof SlsServiceUser) {
            $user = $this->fromHeader($request);
        }
        if (!$user->hasScope($scope)) {
            throw new ContractException(403, 'insufficient_scope', sprintf('The token lacks the "%s" scope.', $scope));
        }
        if ($tenantId !== null && $user->tenantId !== null && !hash_equals($user->tenantId, $tenantId)) {
            throw new ContractException(403, 'tenant_mismatch', 'The token is for another tenant.');
        }

        return $user;
    }

    /** Like {@see require()}, but the token must name a tenant (SCIM: the tenant comes from the token). */
    public function requireTenant(Request $request, string $scope): SlsServiceUser
    {
        $user = $this->require($request, $scope);
        if ($user->tenantId === null) {
            throw new ContractException(403, 'tenant_required', 'The token names no tenant.');
        }

        return $user;
    }

    private function fromHeader(Request $request): SlsServiceUser
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw new ContractException(401, 'unauthorized', 'An SLS service token is required.');
        }
        try {
            return $this->tokenHandler->userFrom($m[1]);
        } catch (AuthenticationException) {
            throw new ContractException(401, 'invalid_token', 'The SLS service token is invalid.');
        }
    }
}
