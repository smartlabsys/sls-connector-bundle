<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning;

use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedJob;

/**
 * A seed handler that also moves configuration between tenants as a file (optional, doc 08 "Seed
 * packages"): SLS asks one tenant what it can export, exports the admin's selection as a package
 * and later imports that package into another tenant. The app owns `data`; the bundle owns the
 * envelope (`format`, `schema_version`, …) and has already checked it against the `seed_packages`
 * config before `previewPackage()` / `importPackage()` are called.
 *
 * Imports follow the seed rules: idempotent (same `idempotencyKey`, same job), never delete, skip
 * what the tenant already has. Their jobs are read back through {@see SeedHandlerInterface::status()}.
 */
interface SeedPackageHandlerInterface extends SeedHandlerInterface
{
    /**
     * What the tenant can export, by section. `requires` lists sections whose rows the section's rows
     * point at (the export adds the ones needed); `items` are the rows the admin can pick.
     *
     * @return list<array{key: string, label: array<string, string>, requires?: list<string>, items: list<array{id: string, label: string}>}>
     */
    public function packageCatalog(string $tenantId): array;

    /**
     * @param array<string, list<string>|true> $selection section key → item ids, or true for all of it
     *
     * @return array{data: array<string, mixed>, contents: array<string, int>, source?: array<string, mixed>}
     *                                         the app's data and its counts per section; the bundle adds the envelope
     */
    public function exportPackage(string $tenantId, array $selection): array;

    /**
     * Dry run: what an import would do, changing nothing.
     *
     * @param array<string, mixed> $package the whole package, envelope included
     * @param array<string, mixed> $context SLS org context, as for seeds
     *
     * @return array{sections: array<string, array{create: int, skip: int}>, warnings: list<string>}
     */
    public function previewPackage(string $tenantId, array $package, array $context): array;

    /**
     * @param array<string, mixed> $package
     * @param array<string, mixed> $context
     */
    public function importPackage(string $tenantId, array $package, array $context, ?string $idempotencyKey): SeedJob;
}
