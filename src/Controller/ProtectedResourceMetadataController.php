<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OAuth 2.0 Protected Resource Metadata (RFC 9728) — public (doc 09 §4). MCP clients follow the
 * `resource_metadata` of a 401 challenge ({@see \Smartlabsys\SlsConnectorBundle\Security\SlsBearerChallenge})
 * here to find SLS as the authorization server, then ask SLS for a token with this `resource`.
 *
 * `/.well-known/oauth-protected-resource` describes the app (`resource` = its audience);
 * `/.well-known/oauth-protected-resource{endpoints.mcp}` its MCP server, when one is configured.
 * Both resources resolve to the same token `aud` (the audience) at SLS.
 */
final class ProtectedResourceMetadataController
{
    public const PATH = '/.well-known/oauth-protected-resource';

    /** @param array<string, mixed> $config the processed `sls_connector` configuration */
    public function __construct(
        private array $config,
        private string $issuer,
        private string $audience,
    ) {}

    #[Route(self::PATH . '{path}', name: 'sls_connector.protected_resource', requirements: ['path' => '(/.+)?'], defaults: ['path' => ''], methods: ['GET'])]
    public function __invoke(string $path = ''): JsonResponse
    {
        $mcp = $this->config['endpoints']['mcp'] ?? null;
        if ($path !== '' && $path !== $mcp) {
            throw new NotFoundHttpException();
        }

        $response = new JsonResponse([
            'resource'                 => $this->audience . $path,
            'authorization_servers'    => [$this->issuer],
            'bearer_methods_supported' => ['header'],
            'scopes_supported'         => array_values(array_filter(explode(' ', (string) $this->config['oidc']['scopes']))),
            'resource_name'            => $this->config['app']['name'],
        ]);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Cache-Control', 'public, max-age=300');

        return $response;
    }
}
