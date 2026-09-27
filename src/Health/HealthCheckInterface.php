<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An app-side check reported by `/sls/health` (database, queue, …). Any failing check makes the
 * instance `degraded` in SLS.
 */
#[AutoconfigureTag('sls_connector.health_check')]
interface HealthCheckInterface
{
    public function name(): string;

    /** Null when healthy, otherwise a short problem description (no secrets). */
    public function check(): ?string;
}
