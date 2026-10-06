<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Test;

use Firebase\JWT\JWT;

/**
 * Mints tokens the way SLS does, signed with a throwaway RSA key, for contract and functional
 * tests. {@see jwks()} is the matching key set to write to the app's `jwks_file` in the test env.
 */
final class SlsTestTokens
{
    private static ?self $shared = null;

    private function __construct(
        private string $privateKey,
        private array $publicJwk,
        public readonly string $kid,
    ) {}

    /** One key pair per test process — generating RSA keys is slow. */
    public static function shared(): self
    {
        return self::$shared ??= self::generate();
    }

    public static function generate(): self
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Could not generate an RSA key: ' . openssl_error_string());
        }
        $details = openssl_pkey_get_details($key);
        $kid     = 'sls-test-' . bin2hex(random_bytes(4));

        return new self($pem, [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n'   => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e'   => JWT::urlsafeB64Encode($details['rsa']['e']),
        ], $kid);
    }

    /** @return array{keys: list<array<string, string>>} */
    public function jwks(): array
    {
        return ['keys' => [$this->publicJwk]];
    }

    /**
     * An SLS → app service token (doc 05 "Service authentication").
     *
     * @param string[]             $scopes
     * @param array<string, mixed> $claims overrides (null removes a claim)
     * @param array<string, mixed> $header overrides
     */
    public function serviceToken(string $issuer, string $audience, array $scopes, ?string $tenantId = null, array $claims = [], array $header = []): string
    {
        $now = time();

        return $this->sign([
            'iss'       => $issuer,
            'sub'       => 'sls',
            'client_id' => 'sls',
            'aud'       => $audience,
            'jti'       => bin2hex(random_bytes(8)),
            'iat'       => $now,
            'nbf'       => $now,
            'exp'       => $now + 300,
            'scope'     => implode(' ', $scopes),
            'tenant_id' => $tenantId,
        ], $claims, $header + ['typ' => 'at+jwt']);
    }

    /**
     * A sibling app's token for this app (doc 09, client credentials with `resource`): `sub` =
     * `client_id` = the caller's OAuth client. `$scopes` are the app-link scopes SLS granted
     * (`demo:orders.read`), checked by `SLS_SCOPE:` attributes.
     *
     * @param array<string, mixed> $claims overrides (null removes a claim)
     * @param string[]             $scopes
     */
    public function appToken(string $issuer, string $audience, string $clientId, string $app, string $tenantId, array $claims = [], array $scopes = []): string
    {
        return $this->userAccessToken($issuer, $audience, $clientId, $clientId, $claims + [
            'scope'       => implode(' ', $scopes),
            'app'         => $app,
            'instance_id' => '00000000-0000-4000-8000-000000000001',
            'org_id'      => '00000000-0000-4000-8000-000000000002',
            'tenant_id'   => $tenantId,
        ]);
    }

    /**
     * A user access token for the app's API / MCP.
     *
     * @param array<string, mixed> $claims
     */
    public function userAccessToken(string $issuer, string $audience, string $clientId, string $sub, array $claims = []): string
    {
        $now = time();

        return $this->sign([
            'iss'       => $issuer,
            'sub'       => $sub,
            'client_id' => $clientId,
            'aud'       => $audience,
            'jti'       => bin2hex(random_bytes(8)),
            'iat'       => $now,
            'nbf'       => $now,
            'exp'       => $now + 300,
            'scope'     => 'openid profile email',
        ], $claims, ['typ' => 'at+jwt']);
    }

    /** @param array<string, mixed> $claims */
    public function logoutToken(string $issuer, string $clientId, ?string $sub, ?string $sid, array $claims = []): string
    {
        $now = time();

        return $this->sign([
            'iss'    => $issuer,
            'aud'    => $clientId,
            'iat'    => $now,
            'exp'    => $now + 120,
            'jti'    => bin2hex(random_bytes(8)),
            'sub'    => $sub,
            'sid'    => $sid,
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass()],
        ], $claims, ['typ' => 'logout+jwt']);
    }

    /** @param array<string, mixed> $claims */
    public function idToken(string $issuer, string $clientId, string $sub, string $nonce, array $claims = []): string
    {
        $now = time();

        return $this->sign([
            'iss'       => $issuer,
            'sub'       => $sub,
            'aud'       => $clientId,
            'azp'       => $clientId,
            'iat'       => $now,
            'exp'       => $now + 300,
            'auth_time' => $now,
            'nonce'     => $nonce,
        ], $claims, []);
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $header
     */
    private function sign(array $defaults, array $overrides, array $header): string
    {
        $payload = array_filter(array_replace($defaults, $overrides), static fn ($v): bool => $v !== null);

        return JWT::encode($payload, $this->privateKey, 'RS256', $header['kid'] ?? $this->kid, $header);
    }
}
