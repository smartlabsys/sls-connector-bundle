<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Psr\Cache\CacheItemPoolInterface;
use Smartlabsys\SlsConnectorBundle\Client\CacheGenerations;
use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\InvalidTokenException;

/**
 * Partner tokens (0.3.3): an access token with a `partnership_id` claim comes over a partnership,
 * usually from another organization. Its signature alone doesn't say the partnership is still
 * active, so it is checked with SLS's token introspection (RFC 7662); SLS revokes the tokens of a
 * partnership when it ends and answers `active: false` for them.
 *
 * Answers are cached per token (`jti`) for {@see self::TTL} seconds at most, never beyond the
 * token's `exp`, and dropped on `partnership.*` webhooks ({@see self::forget()}).
 *
 * **Fail closed**: when SLS can't be reached and no cached answer is left, the token is refused —
 * partner tokens cross organizations, so an unverifiable one is not trusted. Same-org link tokens
 * (no `partnership_id`) are not introspected and keep working while SLS is down.
 *
 * Turn it off with `sls_connector.api.introspect_partner_tokens: false` (e.g. `when@test` when the
 * app's tests mint partner tokens without an SLS to ask; or call {@see self::remember()}).
 */
final class PartnerTokenIntrospector
{
    public const TTL = 60;

    private const GROUP  = 'introspection';
    private const PREFIX = 'sls_connector.introspection.';

    private CacheGenerations $generations;

    public function __construct(
        private SlsClient $client,
        private CacheItemPoolInterface $cache,
        private bool $introspectPartnerTokens = true,
    ) {
        $this->generations = new CacheGenerations($cache);
    }

    /** Whether these validated claims are a partner token this introspector must check. */
    public function applies(array $claims): bool
    {
        return $this->introspectPartnerTokens && is_string($claims['partnership_id'] ?? null) && $claims['partnership_id'] !== '';
    }

    /**
     * @param array<string, mixed> $claims the token's validated claims
     *
     * @throws InvalidTokenException   SLS says the token is no longer active
     * @throws SlsUnavailableException SLS could not be asked (the caller refuses the token)
     */
    public function assertActive(#[\SensitiveParameter] string $jwt, array $claims): void
    {
        if (!$this->applies($claims)) {
            return;
        }
        $item = $this->cache->getItem($this->key($jwt, $claims));
        if ($item->isHit()) {
            $active = $item->get() === true;
        } else {
            $answer = $this->client->introspect($jwt);
            $active = $answer['active'] === true
                && (!isset($answer['jti'], $claims['jti']) || $answer['jti'] === $claims['jti']);
            $this->store($item, $active, $claims);
        }
        if (!$active) {
            throw new InvalidTokenException('SLS no longer accepts this partner token (the partnership may have ended).');
        }
    }

    /**
     * Record an answer without asking SLS, e.g. in tests that mint partner tokens.
     *
     * @param array<string, mixed>|null $claims the token's claims, when the caller has them
     */
    public function remember(#[\SensitiveParameter] string $jwt, bool $active, ?array $claims = null): void
    {
        $claims ??= self::unverifiedClaims($jwt);
        $this->store($this->cache->getItem($this->key($jwt, $claims)), $active, $claims, 900);
    }

    /** Drop every cached answer: the next partner token is checked with SLS again. */
    public function forget(): void
    {
        $this->generations->bump(self::GROUP);
    }

    /** @param array<string, mixed> $claims */
    private function store(\Psr\Cache\CacheItemInterface $item, bool $active, array $claims, int $ttl = self::TTL): void
    {
        $ttl = min($ttl, (int) ($claims['exp'] ?? 0) - time());
        if ($ttl > 0) {
            $this->cache->save($item->set($active)->expiresAfter($ttl));
        }
    }

    /** @param array<string, mixed> $claims */
    private function key(string $jwt, array $claims): string
    {
        $id = is_string($claims['jti'] ?? null) && $claims['jti'] !== '' ? 'jti:' . $claims['jti'] : 'jwt:' . $jwt;

        return self::PREFIX . $this->generations->current(self::GROUP) . '.' . hash('sha256', $id);
    }

    /** @return array<string, mixed> */
    private static function unverifiedClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $json  = count($parts) === 3 ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;
        $data  = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : [];
    }
}
