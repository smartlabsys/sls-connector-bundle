<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning\Model;

final class SeedJob
{
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';

    /** @param array<string, int> $summary counts per entity, e.g. ["sample_types" => 12] */
    public function __construct(
        public readonly string $jobId,
        public readonly string $status = self::STATUS_QUEUED,
        public readonly array $summary = [],
        public readonly ?string $error = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'job_id'  => $this->jobId,
            'status'  => $this->status,
            'summary' => $this->summary ?: null,
            'error'   => $this->error,
        ], static fn ($value): bool => $value !== null);
    }
}
