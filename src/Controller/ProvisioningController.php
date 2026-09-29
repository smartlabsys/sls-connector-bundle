<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Controller;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Smartlabsys\SlsConnectorBundle\Provisioning\CancellableSeedHandlerInterface;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedRequest;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\Tenant;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\TenantRequest;
use Smartlabsys\SlsConnectorBundle\Provisioning\SeedHandlerInterface;
use Smartlabsys\SlsConnectorBundle\Provisioning\TenantPreviewInterface;
use Smartlabsys\SlsConnectorBundle\Provisioning\TenantProvisionerInterface;
use Smartlabsys\SlsConnectorBundle\Security\ServiceRequestGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Provisioning API `/sls/provisioning` (doc 05 §3) — service token with `sls:provision`; a token
 * that names a `tenant_id` may only touch that tenant.
 */
#[Route('/sls/provisioning/tenants', name: 'sls_connector.provisioning.')]
final class ProvisioningController
{
    use ContractResponses;

    public const SCOPE = 'sls:provision';

    /** @param array<string, mixed> $config */
    public function __construct(
        private ServiceRequestGuard $guard,
        #[Autowire('%sls_connector.config%')] private array $config,
        private ?TenantProvisionerInterface $provisioner = null,
        private ?SeedHandlerInterface $seedHandler = null,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request): JsonResponse {
            $this->guard->require($request, self::SCOPE);
            $result = $this->provisioner()->create($this->tenantRequest($request));

            return new JsonResponse($result->tenant->toArray(), $result->created ? 201 : 200);
        });
    }

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request): JsonResponse {
            $this->guard->require($request, self::SCOPE);
            $provisioner = $this->provisioner();
            if (!$provisioner instanceof TenantPreviewInterface) {
                throw ContractException::notImplemented('This app does not preview tenants.');
            }

            return new JsonResponse($provisioner->preview($this->tenantRequest($request))->toArray());
        });
    }

    #[Route('/{tenantId}', name: 'show', methods: ['GET'])]
    public function show(Request $request, string $tenantId): JsonResponse
    {
        return $this->handle(function () use ($request, $tenantId): JsonResponse {
            $this->guard->require($request, self::SCOPE, $tenantId);

            return new JsonResponse($this->found($this->provisioner()->get($tenantId))->toArray());
        });
    }

    #[Route('/{tenantId}/suspend', name: 'suspend', methods: ['POST'])]
    public function suspend(Request $request, string $tenantId): JsonResponse
    {
        return $this->handle(function () use ($request, $tenantId): JsonResponse {
            $this->guard->require($request, self::SCOPE, $tenantId);

            return new JsonResponse($this->found($this->provisioner()->suspend($tenantId))->toArray());
        });
    }

    #[Route('/{tenantId}/resume', name: 'resume', methods: ['POST'])]
    public function resume(Request $request, string $tenantId): JsonResponse
    {
        return $this->handle(function () use ($request, $tenantId): JsonResponse {
            $this->guard->require($request, self::SCOPE, $tenantId);

            return new JsonResponse($this->found($this->provisioner()->resume($tenantId))->toArray());
        });
    }

    #[Route('/{tenantId}', name: 'delete', methods: ['DELETE'])]
    public function delete(Request $request, string $tenantId): JsonResponse
    {
        return $this->handle(function () use ($request, $tenantId): JsonResponse {
            $this->guard->require($request, self::SCOPE, $tenantId);
            if (!$this->provisioner()->delete($tenantId)) {
                throw ContractException::notFound('tenant_not_found', 'Unknown tenant.');
            }

            return new JsonResponse(null, 204);
        });
    }

    #[Route('/{tenantId}/seeds', name: 'seed', methods: ['POST'])]
    public function seed(Request $request, string $tenantId): JsonResponse
    {
        return $this->handle(function () use ($request, $tenantId): JsonResponse {
            $this->guard->require($request, self::SCOPE, $tenantId);
            $handler = $this->seedHandler();
            $this->found($this->provisioner()->get($tenantId));

            $body     = $this->jsonBody($request);
            $template = $body['template'] ?? null;
            $version  = $body['version'] ?? null;
            if (!is_string($template) || !is_int($version)) {
                throw ContractException::badRequest('"template" (string) and "version" (integer) are required.');
            }
            $declared = null;
            foreach ($this->config['seed_templates'] as $candidate) {
                if ($candidate['key'] === $template) {
                    $declared = $candidate;
                }
            }
            if ($declared === null) {
                throw new ContractException(422, 'unknown_template', sprintf('This app has no seed template "%s".', $template));
            }
            if ($declared['version'] !== $version) {
                throw new ContractException(409, 'template_version_mismatch', sprintf('Seed template "%s" is at version %d.', $template, $declared['version']));
            }
            foreach (['parameters', 'context'] as $field) {
                if (isset($body[$field]) && !is_array($body[$field])) {
                    throw ContractException::badRequest(sprintf('"%s" must be an object.', $field));
                }
            }

            $job = $handler->start($tenantId, new SeedRequest(
                $template,
                $version,
                $body['parameters'] ?? [],
                $body['context'] ?? [],
                self::optionalString($body['idempotency_key'] ?? null),
            ));

            return new JsonResponse($job->toArray(), 202);
        });
    }

    #[Route('/{tenantId}/seeds/{jobId}', name: 'seed_status', methods: ['GET'])]
    public function seedStatus(Request $request, string $tenantId, string $jobId): JsonResponse
    {
        return $this->handle(function () use ($request, $tenantId, $jobId): JsonResponse {
            $this->guard->require($request, self::SCOPE, $tenantId);
            $job = $this->seedHandler()->status($tenantId, $jobId);
            if ($job === null) {
                throw ContractException::notFound('job_not_found', 'Unknown seed job.');
            }

            return new JsonResponse($job->toArray());
        });
    }

    #[Route('/{tenantId}/seeds/{jobId}', name: 'seed_cancel', methods: ['DELETE'])]
    public function seedCancel(Request $request, string $tenantId, string $jobId): JsonResponse
    {
        return $this->handle(function () use ($request, $tenantId, $jobId): JsonResponse {
            $this->guard->require($request, self::SCOPE, $tenantId);
            $handler = $this->seedHandler();
            if (!$handler instanceof CancellableSeedHandlerInterface) {
                throw ContractException::notImplemented('This app does not cancel seed jobs.');
            }
            if (!$handler->cancel($tenantId, $jobId)) {
                throw ContractException::notFound('job_not_found', 'Unknown seed job.');
            }

            return new JsonResponse(null, 204);
        });
    }

    /** The body of `POST /tenants` and `POST /tenants/preview`. */
    private function tenantRequest(Request $request): TenantRequest
    {
        $body = $this->jsonBody($request);

        $orgId = $body['sls_org_id'] ?? null;
        $org   = $body['organization'] ?? null;
        if (!is_string($orgId) || trim($orgId) === '') {
            throw ContractException::badRequest('"sls_org_id" is required.');
        }
        if (!is_array($org) || !is_string($org['name'] ?? null) || trim($org['name']) === '') {
            throw ContractException::badRequest('"organization.name" is required.');
        }

        return new TenantRequest(
            trim($orgId),
            trim($org['name']),
            self::optionalString($org['slug'] ?? null),
            self::optionalString($org['locale'] ?? null),
            $body,
            is_array($body['claim'] ?? null) ? self::optionalString($body['claim']['owner_email'] ?? null) : null,
        );
    }

    private function provisioner(): TenantProvisionerInterface
    {
        return $this->provisioner ?? throw ContractException::notImplemented('This app does not provision tenants yet.');
    }

    private function seedHandler(): SeedHandlerInterface
    {
        return $this->seedHandler ?? throw ContractException::notImplemented('This app does not run seed templates.');
    }

    private function found(?Tenant $tenant): Tenant
    {
        return $tenant ?? throw ContractException::notFound('tenant_not_found', 'Unknown tenant.');
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
