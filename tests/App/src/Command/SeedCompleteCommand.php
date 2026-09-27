<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Command;

use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Tests\App\Sls\DemoSeedHandler;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Finishes a queued seed job (`DEMO_SEED_ASYNC=1`) and sends `seed.completed` to SLS. */
#[AsCommand('demo:seed:complete', 'Finish a queued seed job and tell SLS')]
final class SeedCompleteCommand
{
    public function __construct(private DemoSeedHandler $seeds, private SlsClient $client) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The tenant id')] string $tenant,
        #[Argument('The job id')] string $job,
        #[Option('Finish it as failed')] bool $fail = false,
    ): int {
        $seedJob = $this->seeds->finish($tenant, $job, $fail);
        if ($seedJob === null) {
            $io->error('Unknown job.');

            return Command::FAILURE;
        }
        $io->success(sprintf('Job %s is %s; SLS answered "%s".', $seedJob->jobId, $seedJob->status, $this->client->seedCompleted($tenant, $seedJob)));

        return Command::SUCCESS;
    }
}
