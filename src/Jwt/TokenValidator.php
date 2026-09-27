<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Jwt;

use Firebase\JWT\JWT;

/**
 * Validates the three kinds of SLS-signed JWTs an app receives (doc 03, doc 05):
 *
 * - access tokens (`typ` at+jwt) — service tokens SLS sends, and user tokens for the app's
 *   API/MCP; `aud` must contain this instance's audience,
 * - ID tokens — `aud` = this app's client_id, matching `nonce`,
 * - back-channel logout tokens (`typ` logout+jwt) — `aud` = client_id, `events` claim, no nonce.
 *
 * All must be RS256, signed by a key in the SLS JWKS, issued by the configured issuer, and within
 * `exp`/`nbf` (30 s leeway). Returns the claims as an array.
 */
final class TokenValidator
{
    public const LEEWAY = 30;

    private const LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function __construct(
        private KeySetProvider $keySet,
        private string $issuer,
        private string $audience,
        private string $clientId,
    ) {}

    /** @return array<string, mixed> */
    public function validateAccessToken(string $jwt): array
    {
        $claims = $this->decode($jwt, 'at+jwt');
        if (!in_array($this->audience, (array) ($claims['aud'] ?? []), true)) {
            throw new InvalidTokenException('The token is not meant for this app (aud).');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new InvalidTokenException('The token has no subject.');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    public function validateIdToken(string $jwt, string $nonce): array
    {
        $claims = $this->decode($jwt, null);
        if (!in_array($this->clientId, (array) ($claims['aud'] ?? []), true)) {
            throw new InvalidTokenException('The ID token is not meant for this app (aud).');
        }
        if (isset($claims['azp']) && $claims['azp'] !== $this->clientId) {
            throw new InvalidTokenException('The ID token was issued to another client (azp).');
        }
        if (!is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw new InvalidTokenException('The ID token nonce does not match.');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new InvalidTokenException('The ID token has no subject.');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    public function validateLogoutToken(string $jwt): array
    {
        $claims = $this->decode($jwt, 'logout+jwt');
        if (!in_array($this->clientId, (array) ($claims['aud'] ?? []), true)) {
            throw new InvalidTokenException('The logout token is not meant for this app (aud).');
        }
        if (!is_array($claims['events'] ?? null) || !array_key_exists(self::LOGOUT_EVENT, $claims['events'])) {
            throw new InvalidTokenException('The logout token has no back-channel logout event.');
        }
        if (array_key_exists('nonce', $claims)) {
            throw new InvalidTokenException('A logout token must not carry a nonce.');
        }
        if (!is_string($claims['sub'] ?? null) && !is_string($claims['sid'] ?? null)) {
            throw new InvalidTokenException('The logout token has neither sub nor sid.');
        }
        if (!isset($claims['iat'])) {
            throw new InvalidTokenException('The logout token has no iat.');
        }

        return $claims;
    }

    /**
     * Verifies the signature, issuer and lifetime; `$type` (when given) must match the `typ` header.
     *
     * @return array<string, mixed>
     */
    private function decode(string $jwt, ?string $type): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidTokenException('Malformed token.');
        }
        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($parts[0]));
        } catch (\Throwable) {
            throw new InvalidTokenException('Malformed token header.');
        }
        if (!is_object($header) || ($header->alg ?? null) !== 'RS256') {
            throw new InvalidTokenException('Unsupported token algorithm.');
        }
        if ($type !== null && !in_array(strtolower((string) ($header->typ ?? '')), [$type, 'application/' . $type], true)) {
            throw new InvalidTokenException(sprintf('Wrong token type, expected "%s".', $type));
        }
        if (!is_string($header->kid ?? null)) {
            throw new InvalidTokenException('The token has no key id.');
        }
        $key = $this->keySet->key($header->kid);

        $leeway      = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;
        try {
            $payload = JWT::decode($jwt, $key);
        } catch (\Throwable $e) {
            throw new InvalidTokenException('Invalid token: ' . $e->getMessage(), 0, $e);
        } finally {
            JWT::$leeway = $leeway;
        }

        $claims = json_decode((string) json_encode($payload), true);
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new InvalidTokenException('The token was issued by someone else (iss).');
        }
        if (!isset($claims['exp'])) {
            throw new InvalidTokenException('The token has no expiry.');
        }

        return $claims;
    }
}
