<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Command;

use Smartlabsys\SlsConnectorBundle\Jwt\KeySetProvider;
use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fetches the SLS discovery document and JWKS into the cache, so the first SLS call after a
 * deploy (or a key rotation) doesn't have to. Run it on deploy.
 */
#[AsCommand('sls:connector:warmup', 'Fetch and cache the SLS discovery document and signing keys')]
final class WarmupCommand extends Command
{
    public function __construct(
        private SlsMetadata $metadata,
        private KeySetProvider $keySet,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $metadata = $this->metadata->all(true);
            $keys     = $this->keySet->keys(true);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('SLS %s: discovery cached, %d signing key(s): %s.', $metadata['issuer'], count($keys), implode(', ', array_keys($keys))));

        return Command::SUCCESS;
    }
}
