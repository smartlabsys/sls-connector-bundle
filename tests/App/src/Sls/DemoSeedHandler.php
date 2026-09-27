<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedJob;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedRequest;
use Smartlabsys\SlsConnectorBundle\Provisioning\SeedHandlerInterface;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;

/**
 * Runs seeds synchronously — the job is `succeeded` right away — or, with `DEMO_SEED_ASYNC=1`,
 * leaves it `queued` until `demo:seed:complete` finishes it and tells SLS (`seed.completed`).
 * Idempotent on the key.
 */
final class DemoSeedHandler implements SeedHandlerInterface
{
    public function __construct(private JsonStore $store, private bool $async = false) {}

    public function start(string $tenantId, SeedRequest $request): SeedJob
    {
        $async = $this->async;

        return $this->store->update(static function (array &$data) use ($tenantId, $request, $async): SeedJob {
            foreach ($data['seeds'][$tenantId] ?? [] as $job) {
                if ($request->idempotencyKey !== null && $job['idempotency_key'] === $request->idempotencyKey) {
                    return self::job($job);
                }
            }
            $id                           = 'job_' . JsonStore::id();
            $data['seeds'][$tenantId][$id] = [
                'job_id'          => $id,
                'status'          => $async ? SeedJob::STATUS_QUEUED : SeedJob::STATUS_SUCCEEDED,
                'summary'         => $async ? [] : ['sample_types' => 3],
                'template'        => $request->template,
                'idempotency_key' => $request->idempotencyKey,
            ];

            return self::job($data['seeds'][$tenantId][$id]);
        });
    }

    public function status(string $tenantId, string $jobId): ?SeedJob
    {
        $job = $this->store->read()['seeds'][$tenantId][$jobId] ?? null;

        return $job !== null ? self::job($job) : null;
    }

    /** Finish a queued job (`demo:seed:complete`); null if unknown. */
    public function finish(string $tenantId, string $jobId, bool $failed): ?SeedJob
    {
        return $this->store->update(static function (array &$data) use ($tenantId, $jobId, $failed): ?SeedJob {
            if (!isset($data['seeds'][$tenantId][$jobId])) {
                return null;
            }
            $data['seeds'][$tenantId][$jobId]['status']  = $failed ? SeedJob::STATUS_FAILED : SeedJob::STATUS_SUCCEEDED;
            $data['seeds'][$tenantId][$jobId]['summary'] = $failed ? [] : ['sample_types' => 3];
            $data['seeds'][$tenantId][$jobId]['error']   = $failed ? 'demo_failure' : null;

            return self::job($data['seeds'][$tenantId][$jobId]);
        });
    }

    /** @param array<string, mixed> $job */
    private static function job(array $job): SeedJob
    {
        return new SeedJob($job['job_id'], $job['status'], $job['summary'], $job['error'] ?? null);
    }
}
