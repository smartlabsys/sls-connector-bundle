<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Controller;

use Smartlabsys\SlsConnectorBundle\Security\SlsUserTokenHandler;
use Smartlabsys\SlsConnectorBundle\Tests\App\Security\DemoUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A minimal MCP server (streamable HTTP, JSON responses only) behind the `mcp` firewall, to try
 * the doc 09 §4 flow: one `whoami` tool answering from the SLS token's claims.
 */
final class McpController
{
    private const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public function __construct(
        private Security $security,
        private string $appKey = '',
    ) {}

    #[Route('/mcp', name: 'demo_mcp', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $message = json_decode($request->getContent(), true);
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            return self::error(null, -32600, 'Invalid request.', Response::HTTP_BAD_REQUEST);
        }
        if (!array_key_exists('id', $message)) {
            return new Response(null, Response::HTTP_ACCEPTED);
        }
        $id = $message['id'];

        return match ($message['method']) {
            'initialize' => self::result($id, [
                'protocolVersion' => in_array($message['params']['protocolVersion'] ?? null, self::PROTOCOL_VERSIONS, true) ? $message['params']['protocolVersion'] : self::PROTOCOL_VERSIONS[0],
                'capabilities'    => ['tools' => new \ArrayObject()],
                'serverInfo'      => ['name' => $this->appKey, 'version' => '1.0.0'],
            ]),
            'ping'       => self::result($id, new \ArrayObject()),
            'tools/list' => self::result($id, ['tools' => [[
                'name'        => 'whoami',
                'description' => 'Who the token belongs to in this app: user, tenant and app roles.',
                'inputSchema' => ['type' => 'object', 'properties' => new \ArrayObject()],
            ]]]),
            'tools/call' => ($message['params']['name'] ?? null) === 'whoami'
                ? self::result($id, ['content' => [['type' => 'text', 'text' => json_encode($this->whoami($request), JSON_UNESCAPED_SLASHES)]], 'structuredContent' => $this->whoami($request), 'isError' => false])
                : self::error($id, -32602, 'Unknown tool.'),
            default      => self::error($id, -32601, 'Method not found.'),
        };
    }

    #[Route('/mcp', name: 'demo_mcp_not_allowed', methods: ['GET', 'DELETE'])]
    public function notAllowed(): Response
    {
        return new Response(null, Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
    }

    /** @return array<string, mixed> */
    private function whoami(Request $request): array
    {
        $user   = $this->security->getUser();
        $claims = $request->attributes->get(SlsUserTokenHandler::CLAIMS_ATTRIBUTE, []);

        return [
            'app'       => $this->appKey,
            'email'     => $user instanceof DemoUser ? $user->email : null,
            'sls_sub'   => $claims['sub'] ?? null,
            'tenant_id' => $claims['tenant_id'] ?? null,
            'roles'     => $claims['roles'] ?? [],
            'client_id' => $claims['client_id'] ?? null,
        ];
    }

    private static function result(mixed $id, mixed $result): JsonResponse
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private static function error(mixed $id, int $code, string $message, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
