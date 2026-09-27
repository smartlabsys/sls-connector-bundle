<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Security;

use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<DemoUser> */
final class DemoUserProvider implements UserProviderInterface
{
    public function __construct(private JsonStore $store) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $row = $this->store->read()['users'][$identifier] ?? null;

        return $row !== null ? DemoUser::fromRow($row) : throw new UserNotFoundException();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === DemoUser::class;
    }
}
