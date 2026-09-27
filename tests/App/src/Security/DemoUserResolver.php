<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Security;

use Smartlabsys\SlsConnectorBundle\Security\SlsIdentity;
use Smartlabsys\SlsConnectorBundle\Security\SlsUserResolverInterface;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;
use Symfony\Component\Security\Core\User\UserInterface;

/** Links SLS users to local users: by `sub`, else by verified e-mail, else creates one. */
final class DemoUserResolver implements SlsUserResolverInterface
{
    public function __construct(private JsonStore $store) {}

    public function resolveOidcUser(SlsIdentity $identity): ?UserInterface
    {
        $row = $this->store->update(static function (array &$data) use ($identity): array {
            $data['users'] ??= [];
            foreach ($data['users'] as $id => $user) {
                if (($user['sls_sub'] ?? null) === $identity->subject()) {
                    return $user;
                }
            }
            $email = $identity->email();
            if ($email !== null && $identity->emailVerified()) {
                foreach ($data['users'] as $id => $user) {
                    if (strcasecmp($user['email'], $email) === 0 && ($user['sls_sub'] ?? null) === null) {
                        $data['users'][$id]['sls_sub'] = $identity->subject();

                        return $data['users'][$id];
                    }
                }
            }
            $id                 = JsonStore::id();
            $data['users'][$id] = [
                'id'      => $id,
                'email'   => $email ?? $identity->subject() . '@sls.invalid',
                'name'    => $identity->name(),
                'sls_sub' => $identity->subject(),
                'org_id'  => $identity->organizationId(),
            ];

            return $data['users'][$id];
        });

        return DemoUser::fromRow($row);
    }

    /** A signed-in local user, else an active SCIM-provisioned one in the token's tenant (API calls). */
    public function loadBySlsUserId(string $slsUserId, array $claims): ?UserInterface
    {
        $data = $this->store->read();
        foreach ($data['users'] ?? [] as $user) {
            if (($user['sls_sub'] ?? null) === $slsUserId) {
                return DemoUser::fromRow($user);
            }
        }
        foreach ($data['scim_users'][$claims['tenant_id'] ?? ''] ?? [] as $user) {
            if (($user['slsUserId'] ?? null) === $slsUserId && $user['active']) {
                return new DemoUser($user['id'], (string) $user['email'], $user['displayName'], $slsUserId);
            }
        }

        return null;
    }
}
