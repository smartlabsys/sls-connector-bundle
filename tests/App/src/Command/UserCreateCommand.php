<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Command;

use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;
use Smartlabsys\SlsConnectorBundle\Tests\App\Sls\DemoScimUserMapper;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Creates a user locally (not through SLS) and reports it with `user.created` (doc 07 "Inbound from apps"). */
#[AsCommand('demo:user:create', 'Create a local user in a tenant and tell SLS')]
final class UserCreateCommand
{
    public function __construct(private DemoScimUserMapper $users, private SlsClient $client) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The tenant id')] string $tenant,
        #[Argument('The user name')] string $userName,
        #[Option('E-mail')] ?string $email = null,
        #[Option('Given name')] ?string $given = null,
        #[Option('Family name')] ?string $family = null,
        #[Option('Create it inactive')] bool $inactive = false,
        #[Option('Only store it, send no event (for the drift check)')] bool $localOnly = false,
    ): int {
        $user = $this->users->create($tenant, new ScimUser(
            userName: $userName,
            givenName: $given,
            familyName: $family,
            displayName: trim($given . ' ' . $family) ?: null,
            email: $email,
            active: !$inactive,
        ));
        $answer = $localOnly ? 'not told' : '"' . $this->client->userCreated($tenant, $user) . '"';
        $io->success(sprintf('Created %s; SLS answered %s.', $user->id, $answer));

        return Command::SUCCESS;
    }
}
