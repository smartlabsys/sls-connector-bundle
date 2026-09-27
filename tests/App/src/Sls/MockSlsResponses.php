<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test env only: stands in for SLS behind `http_client` (discovery; the token endpoint answers
 * with whatever {@see $token} returns).
 */
final class MockSlsResponses
{
    public const ISSUER = 'https://sls.test';

    /** @var (callable(string, string, array<string, mixed>): ResponseInterface)|null */
    public static $token = null;

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        return match (true) {
            str_ends_with($url, '/.well-known/openid-configuration') => new JsonMockResponse([
                'issuer'                 => self::ISSUER,
                'authorization_endpoint' => self::ISSUER . '/oauth2/authorize',
                'token_endpoint'         => self::ISSUER . '/oauth2/token',
                'userinfo_endpoint'      => self::ISSUER . '/oauth2/userinfo',
                'end_session_endpoint'   => self::ISSUER . '/oauth2/logout',
                'jwks_uri'               => self::ISSUER . '/oauth2/jwks.json',
            ]),
            str_ends_with($url, '/oauth2/token') && self::$token !== null => (self::$token)($method, $url, $options),
            default => new JsonMockResponse(['error' => 'unexpected ' . $method . ' ' . $url], ['http_code' => 500]),
        };
    }
}
