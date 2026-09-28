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

/** Deletes a user locally (not through SLS) and reports it with `user.deleted`. */
#[AsCommand('demo:user:delete', 'Delete a user locally and tell SLS')]
final class UserDeleteCommand
{
    public function __construct(private DemoScimUserMapper $users, private SlsClient $client) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The tenant id')] string $tenant,
        #[Argument('The user id in this app')] string $id,
        #[Option('Only delete it, send no event (for the drift check)')] bool $localOnly = false,
    ): int {
        if (!$this->users->delete($tenant, $id)) {
            $io->error('Unknown user.');

            return Command::FAILURE;
        }
        $answer = $localOnly ? 'not told' : '"' . $this->client->userDeleted($tenant, $id) . '"';
        $io->success(sprintf('Deleted %s; SLS answered %s.', $id, $answer));

        return Command::SUCCESS;
    }
}
