<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Health\HealthCheckInterface;

final class StoreHealthCheck implements HealthCheckInterface
{
    public function __construct(private string $projectDir = __DIR__ . '/../..') {}

    public function name(): string
    {
        return 'store';
    }

    public function check(): ?string
    {
        return is_writable($this->projectDir . '/var') || is_writable($this->projectDir) ? null : 'The store directory is not writable.';
    }
}
