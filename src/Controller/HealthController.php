<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Controller;

use Smartlabsys\SlsConnectorBundle\Health\HealthCheckInterface;
use Smartlabsys\SlsConnectorBundle\Manifest\ManifestBuilder;
use Smartlabsys\SlsConnectorBundle\Security\ServiceRequestGuard;
use Smartlabsys\SlsConnectorBundle\SlsConnectorBundle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** `GET /sls/health` — service token with `sls:health` (doc 05 §7). */
final class HealthController
{
    use ContractResponses;

    public const SCOPE = 'sls:health';

    /** @param iterable<HealthCheckInterface> $checks */
    public function __construct(
        private ServiceRequestGuard $guard,
        private ManifestBuilder $manifest,
        #[AutowireIterator('sls_connector.health_check')] private iterable $checks = [],
    ) {}

    #[Route('/sls/health', name: 'sls_connector.health', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request): JsonResponse {
            $this->guard->require($request, self::SCOPE);

            $results = [];
            foreach ($this->checks as $check) {
                try {
                    $results[$check->name()] = $check->check() ?? 'ok';
                } catch (\Throwable $e) {
                    $results[$check->name()] = 'error: ' . $e::class;
                }
            }
            $degraded = array_filter($results, static fn (string $result): bool => $result !== 'ok') !== [];

            return new JsonResponse(array_filter([
                'status'           => $degraded ? 'degraded' : 'ok',
                'app_version'      => $this->manifest->appVersion(),
                'contract_version' => SlsConnectorBundle::CONTRACT_VERSION,
                'checks'           => $results ?: null,
            ]));
        });
    }
}
