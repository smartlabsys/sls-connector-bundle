<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning;

use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedJob;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedRequest;

/**
 * Implemented by the app (optional): runs its seed templates (doc 08). `start()` should only queue
 * the import (the endpoint answers 202) and must be idempotent — same `idempotencyKey`, same job;
 * re-running a template upserts by natural keys and never deletes customer data. The bundle has
 * already checked the template key and version against the configured `seed_templates`.
 */
interface SeedHandlerInterface
{
    public function start(string $tenantId, SeedRequest $request): SeedJob;

    public function status(string $tenantId, string $jobId): ?SeedJob;
}
