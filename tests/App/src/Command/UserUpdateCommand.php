<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Command;

use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Tests\App\Sls\DemoScimUserMapper;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Changes a user locally (not through SLS) and reports it with `user.updated`. */
#[AsCommand('demo:user:update', 'Change a user locally and tell SLS')]
final class UserUpdateCommand
{
    public function __construct(private DemoScimUserMapper $users, private SlsClient $client) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The tenant id')] string $tenant,
        #[Argument('The user id in this app')] string $id,
        #[Option('E-mail')] ?string $email = null,
        #[Option('Given name')] ?string $given = null,
        #[Option('Family name')] ?string $family = null,
        #[Option('Deactivate it')] bool $inactive = false,
        #[Option('Activate it')] bool $active = false,
        #[Option('Only store it, send no event')] bool $localOnly = false,
    ): int {
        $user = $this->users->get($tenant, $id);
        if ($user === null) {
            $io->error('Unknown user.');

            return Command::FAILURE;
        }
        $user->email      = $email ?? $user->email;
        $user->givenName  = $given ?? $user->givenName;
        $user->familyName = $family ?? $user->familyName;
        $user->active     = $inactive ? false : ($active ? true : $user->active);
        $user             = $this->users->replace($tenant, $user);
        $answer           = $localOnly ? 'not told' : '"' . $this->client->userUpdated($tenant, $user) . '"';
        $io->success(sprintf('Updated %s; SLS answered %s.', $id, $answer));

        return Command::SUCCESS;
    }
}
