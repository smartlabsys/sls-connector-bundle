<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Smartlabsys\SlsConnectorBundle\Controller\ProtectedResourceMetadataController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The RFC 6750 / RFC 9728 challenge for SLS user tokens (doc 09 §4): a JSON 401 with
 * `WWW-Authenticate: Bearer resource_metadata="…"`, plus `error="invalid_token"` when a token was
 * sent but refused. Under `endpoints.mcp` the metadata URL is the MCP server's, elsewhere the app's.
 *
 * Set it as the firewall's `entry_point` (no token) and `access_token.failure_handler` (bad token).
 */
final class SlsBearerChallenge implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    /** @param array<string, mixed> $config the processed `sls_connector` configuration */
    public function __construct(
        private array $config,
        private string $audience,
    ) {}

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->challenge($request, false);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->challenge($request, true);
    }

    public function metadataUrl(Request $request): string
    {
        $mcp  = $this->config['endpoints']['mcp'] ?? null;
        $path = $request->getPathInfo();
        $suffix = $mcp !== null && ($path === $mcp || str_starts_with($path, rtrim($mcp, '/') . '/')) ? $mcp : '';

        return $this->audience . ProtectedResourceMetadataController::PATH . $suffix;
    }

    private function challenge(Request $request, bool $tokenRefused): JsonResponse
    {
        $header = sprintf('Bearer resource_metadata="%s"', $this->metadataUrl($request));
        if ($tokenRefused) {
            $header .= ', error="invalid_token"';
        }

        return new JsonResponse(
            ['error' => $tokenRefused ? 'invalid_token' : 'unauthorized'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => $header],
        );
    }
}
