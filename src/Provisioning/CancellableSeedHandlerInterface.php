<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning;

/**
 * A seed handler that can stop a job (optional, doc 08): an admin cancelled the run in SLS.
 * Stop the job if it's still queued / running; records already created stay. Without it,
 * `DELETE …/seeds/{job}` answers 501 and SLS just stops tracking the job.
 */
interface CancellableSeedHandlerInterface extends SeedHandlerInterface
{
    /** @return bool false when the job is unknown (404); an already finished job is fine (true) */
    public function cancel(string $tenantId, string $jobId): bool;
}
