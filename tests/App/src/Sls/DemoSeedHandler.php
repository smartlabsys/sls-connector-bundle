<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedJob;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedRequest;
use Smartlabsys\SlsConnectorBundle\Provisioning\SeedHandlerInterface;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;

/** Runs seeds synchronously: the job is `succeeded` right away. Idempotent on the key. */
final class DemoSeedHandler implements SeedHandlerInterface
{
    public function __construct(private JsonStore $store) {}

    public function start(string $tenantId, SeedRequest $request): SeedJob
    {
        return $this->store->update(static function (array &$data) use ($tenantId, $request): SeedJob {
            foreach ($data['seeds'][$tenantId] ?? [] as $job) {
                if ($request->idempotencyKey !== null && $job['idempotency_key'] === $request->idempotencyKey) {
                    return self::job($job);
                }
            }
            $id                           = 'job_' . JsonStore::id();
            $data['seeds'][$tenantId][$id] = [
                'job_id'          => $id,
                'status'          => SeedJob::STATUS_SUCCEEDED,
                'summary'         => ['sample_types' => 3],
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

    /** @param array<string, mixed> $job */
    private static function job(array $job): SeedJob
    {
        return new SeedJob($job['job_id'], $job['status'], $job['summary']);
    }
}
