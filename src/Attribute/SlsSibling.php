<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Attribute;

/**
 * Marks a controller (class or action) a sibling app may call as itself (doc 09, 0.3). For a
 * request authenticated as {@see \Smartlabsys\SlsConnectorBundle\Security\SlsAppUser}:
 *   - `scope` (e.g. `qc:requests.write`) must be in the token, else 403 `{"error": "forbidden"}`;
 *   - the token's `tenant_id` is resolved through the app's
 *     {@see \Smartlabsys\SlsConnectorBundle\Security\SlsTenantResolverInterface} (404
 *     `{"error": "tenant_not_found"}` when it returns null) and handed to the action as an
 *     argument typed as the tenant class, and as the request attribute `_sls_tenant`.
 * Requests from signed-in users pass through untouched; the action handles them as before.
 *
 *     #[SlsSibling(scope: 'qc:requests.write')]
 *     #[Route('/api/sibling/requests', methods: ['POST'])]
 *     public function create(Request $request, Company $tenant): JsonResponse
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class SlsSibling
{
    public const TENANT_ATTRIBUTE = '_sls_tenant';

    public function __construct(public readonly ?string $scope = null) {}
}
