<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Jwt;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Psr\Cache\CacheItemPoolInterface;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SLS's public signing keys. Fetched from the discovery document's `jwks_uri` and cached, so
 * validating a token never calls SLS (doc 05: "cache it"). An unknown `kid` (key rotation)
 * triggers one refetch, at most once a minute. With `jwks_file` configured the keys are read
 * from that file instead.
 */
final class KeySetProvider
{
    private const CACHE_KEY         = 'sls_connector.jwks';
    private const REFETCH_GUARD_KEY = 'sls_connector.jwks_refetched';
    private const TTL               = 86400;
    private const REFETCH_INTERVAL  = 60;

    /** @var array<string, mixed>|null */
    private ?array $jwks = null;

    public function __construct(
        private SlsMetadata $metadata,
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        private ?string $jwksFile = null,
    ) {}

    public function key(string $kid): Key
    {
        $keys = $this->keys();
        if (!isset($keys[$kid]) && $this->mayRefetch()) {
            $keys = $this->keys(true);
        }
        if (!isset($keys[$kid])) {
            throw new InvalidTokenException(sprintf('Unknown signing key "%s".', $kid));
        }

        return $keys[$kid];
    }

    /** @return array<string, Key> */
    public function keys(bool $refresh = false): array
    {
        $jwks = $this->jwks($refresh);

        try {
            return JWK::parseKeySet($jwks, 'RS256');
        } catch (\Throwable $e) {
            throw new SlsUnavailableException('The SLS JWKS could not be parsed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    public function jwks(bool $refresh = false): array
    {
        if ($this->jwksFile !== null) {
            return $this->readFile();
        }
        if ($this->jwks !== null && !$refresh) {
            return $this->jwks;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit() && !$refresh) {
            return $this->jwks = $item->get();
        }

        try {
            $data = $this->httpClient->request('GET', $this->metadata->endpoint('jwks_uri'), [
                'headers'       => ['Accept' => 'application/json'],
                'timeout'       => 5,
                'max_duration'  => 10,
                'max_redirects' => 0,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new SlsUnavailableException('Could not fetch the SLS JWKS: ' . $e->getMessage(), 0, $e);
        }
        if (!isset($data['keys']) || !is_array($data['keys'])) {
            throw new SlsUnavailableException('The SLS JWKS has no "keys".');
        }

        $this->cache->save($item->set($data)->expiresAfter(self::TTL));

        return $this->jwks = $data;
    }

    private function mayRefetch(): bool
    {
        if ($this->jwksFile !== null) {
            return true;
        }
        $guard = $this->cache->getItem(self::REFETCH_GUARD_KEY);
        if ($guard->isHit()) {
            return false;
        }
        $this->cache->save($guard->set(true)->expiresAfter(self::REFETCH_INTERVAL));

        return true;
    }

    /** @return array<string, mixed> */
    private function readFile(): array
    {
        $raw  = @file_get_contents($this->jwksFile);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !isset($data['keys'])) {
            throw new SlsUnavailableException(sprintf('The JWKS file "%s" is missing or invalid.', $this->jwksFile));
        }

        return $data;
    }
}
