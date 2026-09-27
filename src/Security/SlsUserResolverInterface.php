<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Implemented by the app: maps SLS identities to local users.
 */
interface SlsUserResolverInterface
{
    /**
     * "Sign in with Smartlab" succeeded. Find the local user by `sub`; else, the first time, by
     * verified email (then store `sub` on it); else create one. Return null to refuse the login
     * (e.g. no access to this tenant) — the user is sent to the failure path.
     */
    public function resolveOidcUser(SlsIdentity $identity): ?UserInterface;

    /**
     * An SLS user access token (audience = this instance) was presented to the app's API or MCP.
     * Return the local user linked to this SLS user id, or null to reject the token.
     *
     * @param array<string, mixed> $claims the validated access token claims (scope, org, roles, …)
     */
    public function loadBySlsUserId(string $slsUserId, array $claims): ?UserInterface;
}
