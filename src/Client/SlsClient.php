<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Client;

use Psr\Cache\CacheItemPoolInterface;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * App → SLS calls with this instance's OAuth client (doc 05 "App → SLS", doc 09):
 * client-credentials service tokens, the token endpoint (code exchange, token exchange), UserInfo,
 * the discovery API (which sibling apps an org is connected to) and calls to those siblings.
 *
 * The discovery API and token exchange are served by SLS from step 8.1.
 */
class SlsClient
{
    public const TOKEN_EXCHANGE_GRANT = 'urn:ietf:params:oauth:grant-type:token-exchange';
    public const ACCESS_TOKEN_TYPE    = 'urn:ietf:params:oauth:token-type:access_token';

    private const DISCOVERY_TTL = 300;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        private SlsMetadata $metadata,
        private string $clientId,
        #[\SensitiveParameter] private string $clientSecret,
    ) {}

    /**
     * A client-credentials access token for this app, cached until 30 s before it expires.
     *
     * @param string[] $scopes
     * @param ?string  $resource audience of a sibling app instance (RFC 8707) — doc 09
     */
    public function serviceToken(array $scopes = [], ?string $resource = null): string
    {
        $item = $this->cache->getItem('sls_connector.service_token.' . sha1(implode(' ', $scopes) . '|' . $resource));
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->tokenRequest(array_filter([
            'grant_type' => 'client_credentials',
            'scope'      => $scopes ? implode(' ', $scopes) : null,
            'resource'   => $resource,
        ]));
        $ttl = (int) ($response['expires_in'] ?? 60) - 30;
        if ($ttl > 0) {
            $this->cache->save($item->set($response['access_token'])->expiresAfter($ttl));
        }

        return $response['access_token'];
    }

    /**
     * RFC 8693: swap a user's access token for one meant for a sibling app instance (`resource` =
     * its audience). SLS only issues it if the user is assigned to the org's connection there.
     *
     * @return array<string, mixed> the token response (`access_token`, `expires_in`, …)
     */
    public function exchangeToken(#[\SensitiveParameter] string $subjectToken, string $resource, ?string $scope = null): array
    {
        return $this->tokenRequest(array_filter([
            'grant_type'         => self::TOKEN_EXCHANGE_GRANT,
            'subject_token'      => $subjectToken,
            'subject_token_type' => self::ACCESS_TOKEN_TYPE,
            'resource'           => $resource,
            'scope'              => $scope,
        ]));
    }

    /**
     * POST to the SLS token endpoint, authenticated with client_secret_basic.
     *
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     *
     * @throws SlsTokenException when SLS answers with an OAuth error
     */
    public function tokenRequest(array $params): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->metadata->endpoint('token_endpoint'), [
                'auth_basic'    => [rawurlencode($this->clientId), rawurlencode($this->clientSecret)],
                'headers'       => ['Accept' => 'application/json'],
                'body'          => $params,
                'timeout'       => 5,
                'max_duration'  => 15,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $data   = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new SlsUnavailableException('The SLS token endpoint could not be reached: ' . $e->getMessage(), 0, $e);
        }
        if ($status !== 200 || !is_string($data['access_token'] ?? null)) {
            throw new SlsTokenException((string) ($data['error'] ?? 'http_' . $status), (string) ($data['error_description'] ?? ''));
        }

        return $data;
    }

    /** @return array<string, mixed> SLS UserInfo for a user access token */
    public function userInfo(#[\SensitiveParameter] string $accessToken): array
    {
        try {
            return $this->httpClient->request('GET', $this->metadata->endpoint('userinfo_endpoint'), [
                'auth_bearer'   => $accessToken,
                'headers'       => ['Accept' => 'application/json'],
                'timeout'       => 5,
                'max_duration'  => 10,
                'max_redirects' => 0,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new SlsUnavailableException('SLS UserInfo failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The org's connected app instances (doc 09 §1), cached for 5 minutes and dropped on
     * `connection.*` webhooks.
     *
     * @return list<array{app: string, instance_id: string, tenant_id: ?string, api_url: ?string, mcp_url: ?string, audience: string, status: string}>
     */
    public function connections(string $organizationId, bool $refresh = false): array
    {
        $item = $this->cache->getItem($this->discoveryCacheKey($organizationId));
        if ($item->isHit() && !$refresh) {
            return $item->get();
        }

        $url = $this->metadata->issuer() . '/api/discovery/organization/' . rawurlencode($organizationId) . '/connections';
        try {
            $data = $this->httpClient->request('GET', $url, [
                'auth_bearer'   => $this->serviceToken(),
                'headers'       => ['Accept' => 'application/json'],
                'timeout'       => 5,
                'max_duration'  => 10,
                'max_redirects' => 0,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new SlsUnavailableException('SLS discovery failed: ' . $e->getMessage(), 0, $e);
        }
        $items = array_values(array_filter($data['items'] ?? [], 'is_array'));
        $this->cache->save($item->set($items)->expiresAfter(self::DISCOVERY_TTL));

        return $items;
    }

    /** @return array<string, mixed>|null the org's active connection to the app with this key */
    public function connection(string $organizationId, string $appKey): ?array
    {
        foreach ($this->connections($organizationId) as $connection) {
            if (($connection['app'] ?? null) === $appKey && ($connection['status'] ?? null) === 'active') {
                return $connection;
            }
        }

        return null;
    }

    public function forgetConnections(string $organizationId): void
    {
        $this->cache->deleteItem($this->discoveryCacheKey($organizationId));
    }

    /**
     * Call a sibling app's API (doc 09 §2): as this app (service token for the sibling's
     * audience), or on behalf of a user when `$userAccessToken` is given (token exchange).
     *
     * @param array<string, mixed> $options Symfony HttpClient options
     */
    public function callSibling(
        string $organizationId,
        string $appKey,
        string $method,
        string $path,
        array $options = [],
        #[\SensitiveParameter] ?string $userAccessToken = null,
    ): ResponseInterface {
        $connection = $this->connection($organizationId, $appKey);
        if ($connection === null || !is_string($connection['api_url'] ?? null)) {
            throw new SlsUnavailableException(sprintf('The organization has no active "%s" connection with an API.', $appKey));
        }
        $token = $userAccessToken !== null
            ? $this->exchangeToken($userAccessToken, $connection['audience'])['access_token']
            : $this->serviceToken([], $connection['audience']);

        return $this->httpClient->request($method, rtrim($connection['api_url'], '/') . '/' . ltrim($path, '/'), $options + [
            'auth_bearer'   => $token,
            'max_redirects' => 0,
        ]);
    }

    private function discoveryCacheKey(string $organizationId): string
    {
        return 'sls_connector.discovery.' . sha1($organizationId);
    }
}
