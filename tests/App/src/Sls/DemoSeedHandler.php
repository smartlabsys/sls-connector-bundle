<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedJob;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedRequest;
use Smartlabsys\SlsConnectorBundle\Provisioning\CancellableSeedHandlerInterface;
use Smartlabsys\SlsConnectorBundle\Provisioning\SeedPackageHandlerInterface;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;

/**
 * Runs seeds synchronously — the job is `succeeded` right away — or, with `DEMO_SEED_ASYNC=1`,
 * leaves it `queued` until `demo:seed:complete` finishes it and tells SLS (`seed.completed`).
 * Idempotent on the key. A cancelled queued job ends as `failed` / `cancelled`.
 *
 * A finished seed gives the tenant three sample types; `demo-config` seed packages carry sample
 * types between tenants (by name: one the tenant has is skipped).
 */
final class DemoSeedHandler implements CancellableSeedHandlerInterface, SeedPackageHandlerInterface
{
    private const SAMPLE_TYPES = ['Food', 'Water', 'Soil'];

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
            if (!$async) {
                self::addSampleTypes($data, $tenantId, self::SAMPLE_TYPES);
            }

            return self::job($data['seeds'][$tenantId][$id]);
        });
    }

    public function status(string $tenantId, string $jobId): ?SeedJob
    {
        $job = $this->store->read()['seeds'][$tenantId][$jobId] ?? null;

        return $job !== null ? self::job($job) : null;
    }

    public function cancel(string $tenantId, string $jobId): bool
    {
        return $this->store->update(static function (array &$data) use ($tenantId, $jobId): bool {
            if (!isset($data['seeds'][$tenantId][$jobId])) {
                return false;
            }
            if (in_array($data['seeds'][$tenantId][$jobId]['status'], [SeedJob::STATUS_QUEUED, SeedJob::STATUS_RUNNING], true)) {
                $data['seeds'][$tenantId][$jobId]['status'] = SeedJob::STATUS_FAILED;
                $data['seeds'][$tenantId][$jobId]['error']  = 'cancelled';
            }

            return true;
        });
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
            if (!$failed) {
                self::addSampleTypes($data, $tenantId, self::SAMPLE_TYPES);
            }

            return self::job($data['seeds'][$tenantId][$jobId]);
        });
    }

    public function packageCatalog(string $tenantId): array
    {
        return [[
            'key'   => 'sample_types',
            'label' => ['en' => 'Sample types', 'sr' => 'Vrste uzoraka'],
            'items' => array_map(static fn (array $t): array => ['id' => $t['id'], 'label' => $t['name']], $this->sampleTypes($tenantId)),
        ]];
    }

    public function exportPackage(string $tenantId, array $selection): array
    {
        $chosen = $selection['sample_types'] ?? [];
        $types  = array_values(array_filter($this->sampleTypes($tenantId), static fn (array $t): bool => $chosen === true || in_array($t['id'], (array) $chosen, true)));
        $names  = array_column($types, 'name');

        return ['data' => ['sample_types' => $names], 'contents' => ['sample_types' => count($names)]];
    }

    public function previewPackage(string $tenantId, array $package, array $context): array
    {
        $names    = self::packageNames($package);
        $existing = array_column($this->sampleTypes($tenantId), 'name');
        $create   = count(array_diff($names, $existing));

        return ['sections' => ['sample_types' => ['create' => $create, 'skip' => count($names) - $create]], 'warnings' => []];
    }

    public function importPackage(string $tenantId, array $package, array $context, ?string $idempotencyKey): SeedJob
    {
        $names = self::packageNames($package);

        return $this->store->update(static function (array &$data) use ($tenantId, $names, $idempotencyKey): SeedJob {
            foreach ($data['seeds'][$tenantId] ?? [] as $job) {
                if ($idempotencyKey !== null && $job['idempotency_key'] === 'package:' . $idempotencyKey) {
                    return self::job($job);
                }
            }
            $created                       = self::addSampleTypes($data, $tenantId, $names);
            $id                            = 'job_' . JsonStore::id();
            $data['seeds'][$tenantId][$id] = [
                'job_id'          => $id,
                'status'          => SeedJob::STATUS_SUCCEEDED,
                'summary'         => ['sample_types.created' => $created, 'sample_types.skipped' => count($names) - $created],
                'template'        => 'package',
                'idempotency_key' => $idempotencyKey !== null ? 'package:' . $idempotencyKey : null,
            ];

            return self::job($data['seeds'][$tenantId][$id]);
        });
    }

    /** @return list<array{id: string, name: string}> */
    private function sampleTypes(string $tenantId): array
    {
        return array_values($this->store->read()['sample_types'][$tenantId] ?? []);
    }

    /** @return list<string> */
    private static function packageNames(array $package): array
    {
        return array_values(array_unique(array_filter($package['data']['sample_types'] ?? [], 'is_string')));
    }

    /**
     * @param list<string> $names
     *
     * @return int how many were new
     */
    private static function addSampleTypes(array &$data, string $tenantId, array $names): int
    {
        $existing = array_column($data['sample_types'][$tenantId] ?? [], 'name');
        $created  = 0;
        foreach ($names as $name) {
            if (!in_array($name, $existing, true)) {
                $data['sample_types'][$tenantId][] = ['id' => 'st_' . JsonStore::id(), 'name' => $name];
                $existing[]                        = $name;
                ++$created;
            }
        }

        return $created;
    }

    /** @param array<string, mixed> $job */
    private static function job(array $job): SeedJob
    {
        return new SeedJob($job['job_id'], $job['status'], $job['summary'], $job['error'] ?? null);
    }
}
