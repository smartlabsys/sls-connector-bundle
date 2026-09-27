<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Controller;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimGroup;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimListResult;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;
use Smartlabsys\SlsConnectorBundle\Scim\ScimFilter;
use Smartlabsys\SlsConnectorBundle\Scim\ScimGroupMapperInterface;
use Smartlabsys\SlsConnectorBundle\Scim\ScimPatch;
use Smartlabsys\SlsConnectorBundle\Scim\ScimSchemas;
use Smartlabsys\SlsConnectorBundle\Scim\ScimUserMapperInterface;
use Smartlabsys\SlsConnectorBundle\Security\ServiceRequestGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SCIM 2.0 server `/scim/v2` (doc 05 §4, doc 07) — service token with `sls:scim`; the tenant is the
 * token's `tenant_id`. The bundle speaks the protocol (list paging, filters, PATCH, errors); the
 * app's {@see ScimUserMapperInterface} / {@see ScimGroupMapperInterface} store the resources.
 */
#[Route('/scim/v2', name: 'sls_connector.scim.')]
final class ScimController
{
    use ContractResponses;

    public const SCOPE        = 'sls:scim';
    public const CONTENT_TYPE = 'application/scim+json';

    private const DEFAULT_COUNT = 100;

    public function __construct(
        private ServiceRequestGuard $guard,
        private ?ScimUserMapperInterface $users = null,
        private ?ScimGroupMapperInterface $groups = null,
    ) {}

    #[Route('/ServiceProviderConfig', name: 'service_provider_config', methods: ['GET'])]
    public function serviceProviderConfig(Request $request): JsonResponse
    {
        return $this->scim(function () use ($request): JsonResponse {
            $this->guard->require($request, self::SCOPE);

            return $this->resource(ScimSchemas::serviceProviderConfig($this->base($request)));
        });
    }

    #[Route('/ResourceTypes/{id}', name: 'resource_types', defaults: ['id' => null], methods: ['GET'])]
    public function resourceTypes(Request $request, ?string $id): JsonResponse
    {
        return $this->scim(function () use ($request, $id): JsonResponse {
            $this->guard->require($request, self::SCOPE);

            return $this->discovery(ScimSchemas::resourceTypes($this->base($request), $this->groups !== null), $id);
        });
    }

    #[Route('/Schemas/{id}', name: 'schemas', defaults: ['id' => null], methods: ['GET'])]
    public function schemas(Request $request, ?string $id): JsonResponse
    {
        return $this->scim(function () use ($request, $id): JsonResponse {
            $this->guard->require($request, self::SCOPE);

            return $this->discovery(ScimSchemas::schemas($this->base($request), $this->groups !== null), $id);
        });
    }

    #[Route('/Users', name: 'users', methods: ['GET'])]
    public function listUsers(Request $request): JsonResponse
    {
        return $this->scim(function () use ($request): JsonResponse {
            $tenant = $this->tenant($request);
            [$filter, $startIndex, $count] = $this->listParameters($request, ScimFilter::USER_ATTRIBUTES);
            $result = $this->users()->list($tenant, $filter, $startIndex, $count);

            return $this->list($result, $startIndex, fn (ScimUser $u): array => $u->toScim($this->userLocation($request, $u)));
        });
    }

    #[Route('/Users', name: 'users.create', methods: ['POST'])]
    public function createUser(Request $request): JsonResponse
    {
        return $this->scim(function () use ($request): JsonResponse {
            $tenant = $this->tenant($request);
            $user   = $this->users()->create($tenant, ScimUser::fromScim($this->jsonBody($request)));
            $url    = $this->userLocation($request, $user);

            return $this->resource($user->toScim($url), 201, $url);
        });
    }

    #[Route('/Users/{id}', name: 'users.show', methods: ['GET'])]
    public function showUser(Request $request, string $id): JsonResponse
    {
        return $this->scim(function () use ($request, $id): JsonResponse {
            $user = $this->existingUser($this->tenant($request), $id);

            return $this->resource($user->toScim($this->userLocation($request, $user)));
        });
    }

    #[Route('/Users/{id}', name: 'users.replace', methods: ['PUT'])]
    public function replaceUser(Request $request, string $id): JsonResponse
    {
        return $this->scim(function () use ($request, $id): JsonResponse {
            $tenant   = $this->tenant($request);
            $existing = $this->existingUser($tenant, $id);
            $user     = ScimUser::fromScim($this->jsonBody($request));
            $user->id      = $existing->id;
            $user->linked  = $existing->linked;
            $user->created = $existing->created;

            return $this->storeUser($request, $tenant, $user);
        });
    }

    #[Route('/Users/{id}', name: 'users.patch', methods: ['PATCH'])]
    public function patchUser(Request $request, string $id): JsonResponse
    {
        return $this->scim(function () use ($request, $id): JsonResponse {
            $tenant     = $this->tenant($request);
            $operations = ScimPatch::operations($this->jsonBody($request));
            $user       = ScimPatch::applyToUser($this->existingUser($tenant, $id), $operations);

            return $this->storeUser($request, $tenant, $user);
        });
    }

    #[Route('/Users/{id}', name: 'users.delete', methods: ['DELETE'])]
    public function deleteUser(Request $request, string $id): JsonResponse
    {
        return $this->scim(function () use ($request, $id): JsonResponse {
            if (!$this->users()->delete($this->tenant($request), $id)) {
                throw self::notFound('User');
            }

            return new JsonResponse(null, 204);
        });
    }

    #[Route('/Groups', name: 'groups', methods: ['GET'])]
    public function listGroups(Request $request): JsonResponse
    {
        return $this->scim(function () use ($request): JsonResponse {
            $tenant = $this->tenant($request);
            [$filter, $startIndex, $count] = $this->listParameters($request, ScimFilter::GROUP_ATTRIBUTES);
            $result = $this->groups()->list($tenant, $filter, $startIndex, $count);

            return $this->list($result, $startIndex, fn (ScimGroup $g): array => $this->groupResource($request, $g));
        });
    }

    #[Route('/Groups', name: 'groups.create', methods: ['POST'])]
    public function createGroup(Request $request): JsonResponse
    {
        return $this->scim(function () use ($request): JsonResponse {
            $tenant = $this->tenant($request);
            $group  = $this->groups()->create($tenant, ScimGroup::fromScim($this->jsonBody($request)));
            $data   = $this->groupResource($request, $group);

            return $this->resource($data, 201, $data['meta']['location']);
        });
    }

    #[Route('/Groups/{id}', name: 'groups.show', methods: ['GET'])]
    public function showGroup(Request $request, string $id): JsonResponse
    {
        return $this->scim(function () use ($request, $id): JsonResponse {
            return $this->resource($this->groupResource($request, $this->existingGroup($this->tenant($request), $id)));
        });
    }

    #[Route('/Groups/{id}', name: 'groups.replace', methods: ['PUT'])]
    public function replaceGroup(Request $request, string $id): JsonResponse
    {
        return $this->scim(function () use ($request, $id): JsonResponse {
            $tenant   = $this->tenant($request);
            $existing = $this->existingGroup($tenant, $id);
            $group    = ScimGroup::fromScim($this->jsonBody($request));
            $group->id      = $existing->id;
            $group->created = $existing->created;

            return $this->storeGroup($request, $tenant, $group);
        });
    }

    #[Route('/Groups/{id}', name: 'groups.patch', methods: ['PATCH'])]
    public function patchGroup(Request $request, string $id): JsonResponse
    {
        return $this->scim(function () use ($request, $id): JsonResponse {
            $tenant     = $this->tenant($request);
            $operations = ScimPatch::operations($this->jsonBody($request));
            $group      = ScimPatch::applyToGroup($this->existingGroup($tenant, $id), $operations);

            return $this->storeGroup($request, $tenant, $group);
        });
    }

    #[Route('/Groups/{id}', name: 'groups.delete', methods: ['DELETE'])]
    public function deleteGroup(Request $request, string $id): JsonResponse
    {
        return $this->scim(function () use ($request, $id): JsonResponse {
            if (!$this->groups()->delete($this->tenant($request), $id)) {
                throw self::notFound('Group');
            }

            return new JsonResponse(null, 204);
        });
    }

    /**
     * Like {@see ContractResponses::handle()}, but errors use the SCIM error schema and every
     * body is `application/scim+json`.
     *
     * @param callable(): JsonResponse $action
     */
    private function scim(callable $action): JsonResponse
    {
        try {
            $response = $action();
        } catch (ContractException $e) {
            $response = new JsonResponse(array_filter([
                'schemas'  => [ScimSchemas::ERROR],
                'status'   => (string) $e->status,
                'scimType' => $e->scimType,
                'detail'   => $e->getMessage(),
            ], static fn ($v): bool => $v !== null), $e->status);
            if ($e->status === 401) {
                $response->headers->set('WWW-Authenticate', 'Bearer error="invalid_token"');
            }
        }
        if ($response->getStatusCode() !== 204) {
            $response->headers->set('Content-Type', self::CONTENT_TYPE);
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function tenant(Request $request): string
    {
        return (string) $this->guard->requireTenant($request, self::SCOPE)->tenantId;
    }

    /**
     * @param string[] $attributes
     *
     * @return array{0: ?ScimFilter, 1: int, 2: int}
     */
    private function listParameters(Request $request, array $attributes): array
    {
        $filter = trim((string) $request->query->get('filter', ''));
        $start  = filter_var($request->query->get('startIndex', '1'), FILTER_VALIDATE_INT);
        $count  = filter_var($request->query->get('count', (string) self::DEFAULT_COUNT), FILTER_VALIDATE_INT);
        if ($start === false || $count === false) {
            throw ContractException::badRequest('"startIndex" and "count" must be integers.', 'invalidValue');
        }

        return [
            $filter !== '' ? ScimFilter::parse($filter, $attributes) : null,
            max(1, $start),
            min(max(0, $count), ScimSchemas::MAX_RESULTS),
        ];
    }

    /** @param callable(ScimUser|ScimGroup): array<string, mixed> $render */
    private function list(ScimListResult $result, int $startIndex, callable $render): JsonResponse
    {
        return new JsonResponse([
            'schemas'      => [ScimSchemas::LIST_RESPONSE],
            'totalResults' => $result->totalResults,
            'startIndex'   => $startIndex,
            'itemsPerPage' => count($result->resources),
            'Resources'    => array_map($render, array_values($result->resources)),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function discovery(array $items, ?string $id): JsonResponse
    {
        if ($id === null) {
            return new JsonResponse([
                'schemas'      => [ScimSchemas::LIST_RESPONSE],
                'totalResults' => count($items),
                'startIndex'   => 1,
                'itemsPerPage' => count($items),
                'Resources'    => $items,
            ]);
        }
        foreach ($items as $item) {
            if (strcasecmp($item['id'], $id) === 0) {
                return $this->resource($item);
            }
        }

        throw ContractException::notFound('not_found', sprintf('"%s" is not known.', $id));
    }

    /** @param array<string, mixed> $data */
    private function resource(array $data, int $status = 200, ?string $location = null): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        if ($location !== null) {
            $response->headers->set('Location', $location);
        }

        return $response;
    }

    private function storeUser(Request $request, string $tenant, ScimUser $user): JsonResponse
    {
        $stored = $this->users()->replace($tenant, $user) ?? throw self::notFound('User');

        return $this->resource($stored->toScim($this->userLocation($request, $stored)));
    }

    private function storeGroup(Request $request, string $tenant, ScimGroup $group): JsonResponse
    {
        $stored = $this->groups()->replace($tenant, $group) ?? throw self::notFound('Group');

        return $this->resource($this->groupResource($request, $stored));
    }

    private function existingUser(string $tenant, string $id): ScimUser
    {
        return $this->users()->get($tenant, $id) ?? throw self::notFound('User');
    }

    private function existingGroup(string $tenant, string $id): ScimGroup
    {
        return $this->groups()->get($tenant, $id) ?? throw self::notFound('Group');
    }

    /** @return array<string, mixed> */
    private function groupResource(Request $request, ScimGroup $group): array
    {
        $base = $this->base($request);

        return $group->toScim($base . '/Groups/' . rawurlencode((string) $group->id), $base . '/Users');
    }

    private function userLocation(Request $request, ScimUser $user): string
    {
        return $this->base($request) . '/Users/' . rawurlencode((string) $user->id);
    }

    private function base(Request $request): string
    {
        return $request->getSchemeAndHttpHost() . $request->getBasePath() . '/scim/v2';
    }

    private function users(): ScimUserMapperInterface
    {
        return $this->users ?? throw ContractException::notImplemented('This app does not accept SCIM users yet.');
    }

    private function groups(): ScimGroupMapperInterface
    {
        return $this->groups ?? throw ContractException::notImplemented('This app does not accept SCIM groups.');
    }

    private static function notFound(string $type): ContractException
    {
        return ContractException::notFound('not_found', sprintf('%s not found.', $type));
    }
}
