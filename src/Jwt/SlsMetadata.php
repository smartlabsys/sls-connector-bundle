<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Jwt;

use Psr\Cache\CacheItemPoolInterface;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SLS's OIDC discovery document (`{issuer}/.well-known/openid-configuration`), cached for an hour.
 * Endpoint URLs (token, authorize, JWKS, end session) always come from here, never hard-coded.
 */
final class SlsMetadata
{
    private const CACHE_KEY = 'sls_connector.metadata';
    private const TTL       = 3600;

    /** @var array<string, mixed>|null */
    private ?array $metadata = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        private string $issuer,
    ) {}

    public function issuer(): string
    {
        return $this->issuer;
    }

    public function endpoint(string $name): string
    {
        $url = $this->all()[$name] ?? null;
        if (!is_string($url) || $url === '') {
            throw new SlsUnavailableException(sprintf('The SLS discovery document has no "%s".', $name));
        }

        return $url;
    }

    /** @return array<string, mixed> */
    public function all(bool $refresh = false): array
    {
        if ($this->metadata !== null && !$refresh) {
            return $this->metadata;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit() && !$refresh) {
            return $this->metadata = $item->get();
        }

        try {
            $data = $this->httpClient->request('GET', $this->issuer . '/.well-known/openid-configuration', [
                'headers'       => ['Accept' => 'application/json'],
                'timeout'       => 5,
                'max_duration'  => 10,
                'max_redirects' => 0,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new SlsUnavailableException('Could not fetch the SLS discovery document: ' . $e->getMessage(), 0, $e);
        }
        if (($data['issuer'] ?? null) !== $this->issuer) {
            throw new SlsUnavailableException(sprintf('The SLS discovery document names issuer "%s", expected "%s".', (string) ($data['issuer'] ?? ''), $this->issuer));
        }

        $this->cache->save($item->set($data)->expiresAfter(self::TTL));

        return $this->metadata = $data;
    }
}
