<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Client;

use Psr\Cache\CacheItemPoolInterface;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedJob;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * App → SLS calls with this instance's OAuth client (doc 05 "App → SLS", doc 09):
 * client-credentials service tokens, the token endpoint (code exchange, token exchange), UserInfo,
 * the discovery API (which sibling apps an org is connected to, which connections a tenant is
 * linked to), the partner API (directory and partnerships, doc 09 §2b), calls to those siblings and events sent to SLS (`seed.completed`, `user.created|updated|deleted`,
 * and the app's own events SLS brokers to linked siblings, {@see self::emit()}).
 */
class SlsClient
{
    public const TOKEN_EXCHANGE_GRANT = 'urn:ietf:params:oauth:grant-type:token-exchange';
    public const ACCESS_TOKEN_TYPE    = 'urn:ietf:params:oauth:token-type:access_token';

    /** Platform scopes for the partner API (doc 09 §2b), requested on a plain client-credentials token. */
    public const SCOPE_DIRECTORY_READ       = 'sls:directory.read';
    public const SCOPE_PARTNERSHIPS_READ    = 'sls:partnerships.read';
    public const SCOPE_PARTNERSHIPS_MANAGE  = 'sls:partnerships.manage';

    private const DISCOVERY_TTL = 300;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        private SlsMetadata $metadata,
        private string $clientId,
        #[\SensitiveParameter] private string $clientSecret,
        private array $config = [],
    ) {}

    /**
     * A client-credentials access token for this app, cached until 30 s before it expires.
     * Tokens for a sibling app are also dropped on `link.*`, `connection.*` and `partnership.*`
     * webhooks for the calling tenant or org ({@see self::forgetSiblingTokens()}, 0.3.3), so a
     * partnership that ended doesn't leave a cached token behind.
     *
     * For a sibling app SLS needs the connection the call is for: `$targetConnectionId` (a
     * `connection_id` from discovery, doc 09 10.4), or `$resource` (RFC 8707) with the org whose
     * connection to that instance it is. The token carries the target's `tenant_id`.
     * `$callerTenantId` names this app's tenant the call comes from, when it has several in the org.
     *
     * @param string[] $scopes
     * @param ?string  $resource           audience of a sibling app instance — doc 09
     * @param ?string  $organizationId     SLS org id, required with `$resource` alone
     * @param ?string  $targetConnectionId the sibling's connection id
     * @param ?string  $callerTenantId     this app's tenant making the call
     */
    public function serviceToken(
        array $scopes = [],
        ?string $resource = null,
        ?string $organizationId = null,
        ?string $targetConnectionId = null,
        ?string $callerTenantId = null,
    ): string {
        if ($resource !== null && $organizationId === null && $targetConnectionId === null) {
            throw new \InvalidArgumentException('A service token for a sibling app needs the organization id or the target connection.');
        }
        $sibling = $resource !== null || $targetConnectionId !== null;
        $item    = $this->cache->getItem('sls_connector.service_token.' . sha1(implode(' ', $scopes) . '|' . $resource . '|' . $organizationId . '|' . $targetConnectionId . '|' . $callerTenantId
            . ($sibling ? '|' . $this->siblingTokenGeneration($organizationId, $callerTenantId) : '')));
        if ($item->isHit()) {
            return $item->get();
        }

        $response = $this->tokenRequest(array_filter([
            'grant_type'        => 'client_credentials',
            'scope'             => $scopes ? implode(' ', $scopes) : null,
            'resource'          => $resource,
            'target_connection' => $targetConnectionId,
            'org_id'            => $sibling ? $organizationId : null,
            'caller_tenant_id'  => $sibling ? $callerTenantId : null,
        ]));
        $ttl = (int) ($response['expires_in'] ?? 60) - 30;
        if ($ttl > 0) {
            $this->cache->save($item->set($response['access_token'])->expiresAfter($ttl));
        }

        return $response['access_token'];
    }

    /**
     * RFC 8693: swap a user's access token for one meant for a sibling app instance (`resource` =
     * its audience, or `$targetConnectionId`). SLS only issues it if the user is assigned to that
     * connection. `$callerTenantId` defaults, on SLS's side, to the user token's `tenant_id`.
     *
     * @return array<string, mixed> the token response (`access_token`, `expires_in`, …)
     */
    public function exchangeToken(
        #[\SensitiveParameter] string $subjectToken,
        ?string $resource,
        ?string $scope = null,
        ?string $targetConnectionId = null,
        ?string $callerTenantId = null,
    ): array {
        if (($resource === null || $resource === '') && ($targetConnectionId === null || $targetConnectionId === '')) {
            throw new \InvalidArgumentException('A token exchange needs the resource or the target connection.');
        }

        return $this->tokenRequest(array_filter([
            'grant_type'         => self::TOKEN_EXCHANGE_GRANT,
            'subject_token'      => $subjectToken,
            'subject_token_type' => self::ACCESS_TOKEN_TYPE,
            'resource'           => $resource,
            'target_connection'  => $targetConnectionId,
            'caller_tenant_id'   => $callerTenantId,
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
     * @return list<array{app: string, connection_id?: string, instance_id: string, instance_name?: string, tenant_id: ?string, api_url: ?string, mcp_url: ?string, audience: string, status: string}>
     */
    public function connections(string $organizationId, bool $refresh = false): array
    {
        $item = $this->cache->getItem($this->discoveryCacheKey($organizationId));
        if ($item->isHit() && !$refresh) {
            return $item->get();
        }

        $items = $this->discover('/api/discovery/organization/' . rawurlencode($organizationId) . '/connections');
        $this->cache->save($item->set($items)->expiresAfter(self::DISCOVERY_TTL));

        return $items;
    }

    /**
     * The org's active connection to the app with this key: the given instance, or the first one
     * when the org is connected to several instances of the app and `$instanceId` is null.
     *
     * @return array<string, mixed>|null
     */
    public function connection(string $organizationId, string $appKey, ?string $instanceId = null): ?array
    {
        foreach ($this->connections($organizationId) as $connection) {
            if (($connection['app'] ?? null) === $appKey && ($connection['status'] ?? null) === 'active'
                && ($instanceId === null || ($connection['instance_id'] ?? null) === $instanceId)) {
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
     * The connections this app's tenant is linked to (doc 09 §1, 10.4): inside its company and to
     * other companies, enabled links only, each with `link_id` and the granted `scopes`. Partners
     * in other organizations are listed too (`link_id` null, `partnership: {id, role, side}`).
     * Cached for 5 minutes and dropped on `link.*` / `connection.*` / `partnership.*` webhooks for
     * that tenant.
     *
     * A tenant whose connection isn't active (SLS answers 404) has no links: the answer is an
     * empty list, cached like any other (0.3.1).
     *
     * @return list<array{app: string, connection_id: string, instance_id: string, tenant_id: ?string, company_id: ?string, company_name: ?string, api_url: ?string, mcp_url: ?string, audience: string, status: string, link_id: ?string, scopes: list<string>, declared: bool, same_company: bool, partnership?: array{id: string, role: string, side: string}}>
     *
     * @throws SlsUnavailableException SLS could not be reached, failed, or refused this app's service token
     */
    public function links(string $tenantId, bool $refresh = false): array
    {
        $item = $this->cache->getItem($this->linksCacheKey($tenantId));
        if ($item->isHit() && !$refresh) {
            return $item->get();
        }

        $items = $this->discover('/api/discovery/tenant/' . rawurlencode($tenantId) . '/links', notFoundIsEmpty: true);
        $this->cache->save($item->set($items)->expiresAfter(self::DISCOVERY_TTL));

        return $items;
    }

    /**
     * The tenant's active links to an instance of the scope's app (`qc:requests.write` → `qc`)
     * that grant `$scope`, those inside the tenant's own company first (0.3.1).
     *
     * @return list<array<string, mixed>>
     */
    public function linksGranting(string $tenantId, string $scope): array
    {
        $app = strstr($scope, ':', true);
        if ($app === false || $app === '') {
            return [];
        }

        $granting = array_values(array_filter(
            $this->links($tenantId),
            static fn (array $link): bool => ($link['app'] ?? null) === $app
                && ($link['status'] ?? null) === 'active'
                && in_array($scope, is_array($link['scopes'] ?? null) ? $link['scopes'] : [], true),
        ));
        usort($granting, static fn (array $a, array $b): int => (int) (($b['same_company'] ?? false) === true) <=> (int) (($a['same_company'] ?? false) === true));

        return $granting;
    }

    /**
     * The link to use for `$scope`: the first of {@see self::linksGranting()}, or null (0.3.1).
     *
     * @return array<string, mixed>|null
     */
    public function linkGranting(string $tenantId, string $scope): ?array
    {
        return $this->linksGranting($tenantId, $scope)[0] ?? null;
    }

    /**
     * The tenant's links that come from a partnership in `$role`, on `$side` (this tenant's side,
     * `provider` or `customer`) when given, whatever their connection status (0.3.1).
     *
     * @return list<array<string, mixed>>
     */
    public function partnerLinks(string $tenantId, string $role, ?string $side = null, bool $refresh = false): array
    {
        return array_values(array_filter(
            $this->links($tenantId, $refresh),
            static fn (array $link): bool => is_array($link['partnership'] ?? null)
                && ($link['partnership']['role'] ?? null) === $role
                && ($side === null || ($link['partnership']['side'] ?? null) === $side),
        ));
    }

    /**
     * One linked connection of the tenant: by connection id, or the first active one of the app
     * with that key.
     *
     * @return array<string, mixed>|null
     */
    public function link(string $tenantId, string $connectionIdOrAppKey): ?array
    {
        $byApp = null;
        foreach ($this->links($tenantId) as $link) {
            if (($link['connection_id'] ?? null) === $connectionIdOrAppKey) {
                return $link;
            }
            if ($byApp === null && ($link['app'] ?? null) === $connectionIdOrAppKey && ($link['status'] ?? null) === 'active') {
                $byApp = $link;
            }
        }

        return $byApp;
    }

    public function forgetLinks(string $tenantId): void
    {
        $this->cache->deleteItem($this->linksCacheKey($tenantId));
    }

    /**
     * Drop the cached service tokens for sibling apps (0.3.3): those this tenant called with
     * (`$callerTenantId` / {@see self::callLink()}) and those for this org (`$organizationId` /
     * {@see self::callSibling()} without a tenant). Sibling tokens that name neither are dropped
     * every time. Plain tokens for SLS itself are kept. The next call asks SLS again, which
     * refuses a target the tenant is no longer linked or partnered to.
     */
    public function forgetSiblingTokens(?string $tenantId = null, ?string $organizationId = null): void
    {
        $generations = new CacheGenerations($this->cache);
        $generations->bump('service_token.any');
        if ($tenantId !== null) {
            $generations->bump('service_token.tenant.' . $tenantId);
        }
        if ($organizationId !== null) {
            $generations->bump('service_token.org.' . $organizationId);
        }
    }

    /**
     * RFC 7662: ask SLS whether an access token is still live, authenticated as this app's client
     * (0.3.3). SLS answers `active: false` for a token it revoked, e.g. one issued under a
     * partnership that has since ended.
     *
     * @return array<string, mixed> SLS's answer (`active`, and `client_id`, `exp`, `jti`, … when active)
     *
     * @throws SlsUnavailableException SLS could not be reached, refused this client, or answered oddly
     */
    public function introspect(#[\SensitiveParameter] string $token): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->metadata->endpoint('introspection_endpoint'), [
                'auth_basic'    => [rawurlencode($this->clientId), rawurlencode($this->clientSecret)],
                'headers'       => ['Accept' => 'application/json'],
                'body'          => ['token' => $token, 'token_type_hint' => 'access_token'],
                'timeout'       => 3,
                'max_duration'  => 5,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $data   = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new SlsUnavailableException('The SLS introspection endpoint could not be reached: ' . $e->getMessage(), 0, $e);
        }
        if ($status !== 200 || !is_bool($data['active'] ?? null)) {
            throw new SlsUnavailableException(sprintf('SLS token introspection answered HTTP %d%s.', $status, isset($data['error']) ? ' (' . (string) $data['error'] . ')' : ''));
        }

        return $data;
    }

    /**
     * Call a connection the tenant is linked to (doc 09 §2, 10.4), by connection id or app key.
     * The token names both ends (`target_connection`, `caller_tenant_id`), so it works between
     * companies and when the org has several connections to the same app. As this app, or on
     * behalf of a user when `$userAccessToken` is given (token exchange).
     *
     * 0.3.1: `$scopes` narrows the token to those of the link's scopes (all of them when empty).
     * `$fromInstanceRoot` resolves `$path` against the instance's base URL (the link's `audience`)
     * instead of its `api_url`, for an API the manifest's `endpoints.api` doesn't cover.
     *
     * @param array<string, mixed> $options Symfony HttpClient options
     * @param string[]             $scopes
     *
     * @throws SlsUnavailableException no such link, or the linked connection has no API
     * @throws SlsTokenException       SLS refused the token (e.g. `invalid_target`: the link is off)
     */
    public function callLink(
        string $tenantId,
        string $connectionIdOrAppKey,
        string $method,
        string $path,
        array $options = [],
        #[\SensitiveParameter] ?string $userAccessToken = null,
        array $scopes = [],
        bool $fromInstanceRoot = false,
    ): ResponseInterface {
        $link = $this->link($tenantId, $connectionIdOrAppKey);
        $base = $fromInstanceRoot ? ($link['audience'] ?? null) : ($link['api_url'] ?? null);
        if ($link === null || ($link['status'] ?? null) !== 'active' || !is_string($base) || $base === '' || !is_string($link['connection_id'] ?? null)) {
            throw new SlsUnavailableException(sprintf('Tenant "%s" has no active link to "%s" with an API.', $tenantId, $connectionIdOrAppKey));
        }
        $token = $userAccessToken !== null
            ? $this->exchangeToken($userAccessToken, null, $scopes ? implode(' ', $scopes) : null, $link['connection_id'], $tenantId)['access_token']
            : $this->serviceToken($scopes, null, null, $link['connection_id'], $tenantId);

        return $this->httpClient->request($method, rtrim($base, '/') . '/' . ltrim($path, '/'), $options + [
            'auth_bearer'   => $token,
            'max_redirects' => 0,
        ]);
    }

    /**
     * Call a sibling app's API (doc 09 §2): as this app (service token for the sibling's
     * audience), or on behalf of a user when `$userAccessToken` is given (token exchange).
     * `$instanceId` picks one instance when the org is connected to several of the app.
     *
     * `$connectionId` (0.3) names the sibling's exact connection, e.g. `source.connection_id` of a
     * brokered event. With `$tenantId` (this app's tenant) the call goes through
     * {@see self::callLink()}, which also works between companies; without it the connection is
     * looked up in the org's connections and the token names it as `target_connection`.
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
        ?string $instanceId = null,
        ?string $connectionId = null,
        ?string $tenantId = null,
    ): ResponseInterface {
        if ($connectionId !== null) {
            if ($tenantId !== null) {
                return $this->callLink($tenantId, $connectionId, $method, $path, $options, $userAccessToken);
            }
            $connection = null;
            foreach ($this->connections($organizationId) as $candidate) {
                if (($candidate['connection_id'] ?? null) === $connectionId && ($candidate['app'] ?? null) === $appKey && ($candidate['status'] ?? null) === 'active') {
                    $connection = $candidate;
                    break;
                }
            }
            if ($connection === null || !is_string($connection['api_url'] ?? null)) {
                throw new SlsUnavailableException(sprintf('The organization has no active "%s" connection %s with an API.', $appKey, $connectionId));
            }
            $token = $userAccessToken !== null
                ? $this->exchangeToken($userAccessToken, null, null, $connectionId)['access_token']
                : $this->serviceToken([], null, $organizationId, $connectionId);

            return $this->httpClient->request($method, rtrim($connection['api_url'], '/') . '/' . ltrim($path, '/'), $options + [
                'auth_bearer'   => $token,
                'max_redirects' => 0,
            ]);
        }

        $connection = $this->connection($organizationId, $appKey, $instanceId);
        if ($connection === null || !is_string($connection['api_url'] ?? null)) {
            throw new SlsUnavailableException(sprintf('The organization has no active "%s" connection%s with an API.', $appKey, $instanceId === null ? '' : ' to instance ' . $instanceId));
        }
        $token = $userAccessToken !== null
            ? $this->exchangeToken($userAccessToken, $connection['audience'])['access_token']
            : $this->serviceToken([], $connection['audience'], $organizationId);

        return $this->httpClient->request($method, rtrim($connection['api_url'], '/') . '/' . ltrim($path, '/'), $options + [
            'auth_bearer'   => $token,
            'max_redirects' => 0,
        ]);
    }

    /**
     * Send an event to SLS (doc 09 "App → SLS"): `POST /api/webhook/cmd/receive` with this app's
     * service token. `$tenantId` is the org's tenant in this app — SLS finds the connection by it.
     * Safe to repeat with the same `$eventId`.
     *
     * @param array<string, mixed> $data
     *
     * @return string SLS's answer: `received`, or `duplicate` when the event was already applied
     *                (`user.*` events can also answer `resynced` or `ignored`)
     *
     * @throws SlsEventException      SLS refused the event (4xx)
     * @throws SlsUnavailableException SLS could not be reached or failed (5xx) — try again later
     */
    public function sendEvent(string $type, string $tenantId, array $data = [], ?string $eventId = null): string
    {
        $body = [
            'event_id'    => $eventId ?? self::uuid(),
            'type'        => $type,
            'tenant_id'   => $tenantId,
            'occurred_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'data'        => (object) $data,
        ];
        try {
            $response = $this->httpClient->request('POST', $this->metadata->issuer() . '/api/webhook/cmd/receive', [
                'auth_bearer'   => $this->serviceToken(),
                'headers'       => ['Accept' => 'application/json'],
                'json'          => $body,
                'timeout'       => 5,
                'max_duration'  => 15,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $answer = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new SlsUnavailableException('SLS did not take the event: ' . $e->getMessage(), 0, $e);
        }
        if ($status >= 500) {
            throw new SlsUnavailableException(sprintf('SLS answered the event with HTTP %d.', $status));
        }
        if ($status !== 200) {
            $errors = array_map(
                static fn (mixed $e): string => is_array($e) ? (string) ($e['message'] ?? '') : (string) $e,
                (array) ($answer['errors'] ?? []),
            );
            throw new SlsEventException($status, $errors);
        }

        return (string) ($answer['status'] ?? 'received');
    }

    /**
     * Send one of this app's own events for SLS to broker to the linked apps that consume it
     * (doc 09 "Events between apps", 0.3). The type must start with this app's key and be listed
     * under `sls_connector.events.emits`. Safe to repeat with the same `$eventId` (a UUID is
     * generated when it is null — pass a stable one to make retries idempotent).
     *
     * `$answer` (0.3.1) receives SLS's answer, as {@see self::sendEvent()} returns it: `received`,
     * or `duplicate` when SLS already had this event id — e.g. for an outbox recording deliveries.
     *
     * @param array<string, mixed> $data at most 64 KB JSON-encoded
     *
     * @return string the event id sent
     *
     * @throws \InvalidArgumentException a type this app doesn't declare — check with {@see self::canEmit()}
     * @throws SlsEventException          SLS refused the event (4xx)
     * @throws SlsUnavailableException    SLS could not be reached or failed (5xx) — try again later
     */
    public function emit(string $type, string $tenantId, array $data = [], ?string $eventId = null, ?string &$answer = null): string
    {
        $appKey = $this->config['app']['key'] ?? null;
        if (!is_string($appKey) || !str_starts_with($type, $appKey . '.')) {
            throw new \InvalidArgumentException(sprintf('Event type "%s" must start with this app\'s key ("%s.").', $type, (string) $appKey));
        }
        if (!in_array($type, $this->config['events']['emits'] ?? [], true)) {
            throw new \InvalidArgumentException(sprintf('Event type "%s" is not listed under sls_connector.events.emits.', $type));
        }
        $eventId ??= self::uuid();
        $answer    = $this->sendEvent($type, $tenantId, $data, $eventId);

        return $eventId;
    }

    /** Whether {@see self::emit()} takes this type: it starts with this app's key and is listed under `events.emits` (0.3.1). */
    public function canEmit(string $type): bool
    {
        $appKey = $this->config['app']['key'] ?? null;

        return is_string($appKey) && str_starts_with($type, $appKey . '.')
            && in_array($type, $this->config['events']['emits'] ?? [], true);
    }

    /**
     * Providers listed for a partnership role that this tenant's organization may see (doc 09 §2b):
     * `connection_id, organization_id, company_name, city, country, public_description, app,
     * app_name, icon_url, instance_name, role, role_label`. Needs `sls:directory.read`.
     *
     * @return list<array<string, mixed>>
     *
     * @throws SlsPartnerException     SLS refused (missing scope, unknown tenant or role)
     * @throws SlsUnavailableException SLS could not be reached or failed
     */
    public function directory(string $role, string $tenantId): array
    {
        $answer = $this->partnerCall('GET', '/directory', [self::SCOPE_DIRECTORY_READ], ['role' => $role, 'tenant_id' => $tenantId]);

        return array_values(array_filter($answer['items'] ?? [], 'is_array'));
    }

    /**
     * The tenant's partnerships, any status, each as SLS describes it (`id, role, status,
     * initiated_by, customer, provider, can_accept, can_decline, can_end`, …; the other side's
     * `tenant_id` only while active). Needs `sls:partnerships.read`.
     *
     * @return list<array<string, mixed>>
     */
    public function partnerships(string $tenantId): array
    {
        $answer = $this->partnerCall('GET', '/partnerships', [self::SCOPE_PARTNERSHIPS_READ], ['tenant_id' => $tenantId]);

        return array_values(array_filter($answer['items'] ?? [], 'is_array'));
    }

    /**
     * Propose a partnership in `$role` between this tenant's connection and `$counterpartConnectionId`
     * (a `connection_id` from {@see self::directory()}, or one the other side gave). This tenant is
     * the customer when the role is this app's, else the provider. Inside one organization SLS
     * makes it active at once; otherwise it stays pending until the other side accepts.
     * Needs `sls:partnerships.manage`.
     *
     * @return array<string, mixed> the partnership
     */
    public function proposePartnership(string $role, string $tenantId, string $counterpartConnectionId): array
    {
        return $this->partnerCall('POST', '/partnerships', [self::SCOPE_PARTNERSHIPS_MANAGE], [], ['role' => $role, 'tenant_id' => $tenantId, 'counterpart' => $counterpartConnectionId]);
    }

    /**
     * Redeem an invite code (`XXXX-XXXX-XXXX`, single use, 14 days) another organization gave: the
     * partnership becomes active with this tenant's connection. Needs `sls:partnerships.manage`.
     *
     * @return array<string, mixed> the partnership
     */
    public function redeemInvite(string $code, string $tenantId): array
    {
        return $this->partnerCall('POST', '/partnerships/redeem', [self::SCOPE_PARTNERSHIPS_MANAGE], [], ['code' => $code, 'tenant_id' => $tenantId]);
    }

    /**
     * Accept a pending partnership proposed to this app's connection; `$tenantId` names another of
     * this app's connections in the same organization to take part instead.
     *
     * @return array<string, mixed> the partnership
     */
    public function acceptPartnership(string $partnershipId, ?string $tenantId = null): array
    {
        return $this->partnerCall('POST', '/partnerships/' . rawurlencode($partnershipId) . '/accept', [self::SCOPE_PARTNERSHIPS_MANAGE], [], array_filter(['tenant_id' => $tenantId]));
    }

    /** @return array<string, mixed> the partnership */
    public function declinePartnership(string $partnershipId): array
    {
        return $this->partnerCall('POST', '/partnerships/' . rawurlencode($partnershipId) . '/decline', [self::SCOPE_PARTNERSHIPS_MANAGE]);
    }

    /** End (or withdraw, while pending) a partnership. @return array<string, mixed> the partnership */
    public function endPartnership(string $partnershipId): array
    {
        return $this->partnerCall('POST', '/partnerships/' . rawurlencode($partnershipId) . '/end', [self::SCOPE_PARTNERSHIPS_MANAGE]);
    }

    /**
     * An asynchronous seed job finished (doc 08): tells SLS now instead of waiting for its next
     * status poll. Idempotent — the event id is derived from the job.
     *
     * @return string `received` or `duplicate`
     */
    public function seedCompleted(string $tenantId, SeedJob $job): string
    {
        return $this->sendEvent('seed.completed', $tenantId, $job->toArray(), self::uuid('seed.completed|' . $tenantId . '|' . $job->jobId));
    }

    /**
     * A user was created locally in this app, outside SLS (doc 07 "Inbound from apps"). SLS lists
     * it as unmanaged for the org's Owners / Admins to adopt. Don't call it for users SLS pushed
     * through SCIM.
     *
     * @return string `received`, `duplicate`, `resynced` or `ignored`
     */
    public function userCreated(string $tenantId, ScimUser $user): string
    {
        return $this->sendEvent('user.created', $tenantId, self::userData($user));
    }

    /**
     * A user's local data changed in this app. For a user SLS manages (`slsUserId` set) SLS
     * compares it with what it pushed and, if it differs, pushes its own version again (SLS wins).
     *
     * @return string `received`, `duplicate`, `resynced` or `ignored`
     */
    public function userUpdated(string $tenantId, ScimUser $user): string
    {
        return $this->sendEvent('user.updated', $tenantId, self::userData($user));
    }

    /**
     * A user was deleted locally in this app. SLS forgets an unmanaged one; a user SLS still
     * assigns is created again.
     *
     * @return string `received`, `duplicate`, `resynced` or `ignored`
     */
    public function userDeleted(string $tenantId, string $appUserId): string
    {
        return $this->sendEvent('user.deleted', $tenantId, ['id' => $appUserId]);
    }

    /** @return array<string, mixed> a `user.*` event's `data` */
    private static function userData(ScimUser $user): array
    {
        if ($user->id === null || $user->id === '') {
            throw new \InvalidArgumentException('A user event needs the user\'s id in this app.');
        }

        return array_filter([
            'id'           => $user->id,
            'user_name'    => $user->userName !== '' ? $user->userName : null,
            'email'        => $user->email,
            'given_name'   => $user->givenName,
            'family_name'  => $user->familyName,
            'display_name' => $user->displayName,
            'active'       => $user->active,
            'sls_user_id'  => $user->slsUserId,
            'roles'        => $user->slsUserId !== null ? array_values($user->roles) : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** A random UUID v4, or a stable UUID-shaped id derived from `$name`. */
    private static function uuid(?string $name = null): string
    {
        $bytes    = $name === null ? random_bytes(16) : substr(hash('sha256', $name, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * GET a discovery endpoint with this app's service token. A refused service token is an
     * SlsUnavailableException like any other SLS failure (0.3.1).
     *
     * @return list<array<string, mixed>> its `items`; empty on a 404 when `$notFoundIsEmpty`
     */
    private function discover(string $path, bool $notFoundIsEmpty = false): array
    {
        try {
            $token = $this->serviceToken();
        } catch (SlsTokenException $e) {
            throw new SlsUnavailableException('SLS refused the service token for discovery: ' . $e->getMessage(), 0, $e);
        }
        try {
            $response = $this->httpClient->request('GET', $this->metadata->issuer() . $path, [
                'auth_bearer'   => $token,
                'headers'       => ['Accept' => 'application/json'],
                'timeout'       => 5,
                'max_duration'  => 10,
                'max_redirects' => 0,
            ]);
            if ($notFoundIsEmpty && $response->getStatusCode() === 404) {
                return [];
            }
            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SlsUnavailableException('SLS discovery failed: ' . $e->getMessage(), 0, $e);
        }

        return array_values(array_filter($data['items'] ?? [], 'is_array'));
    }

    /**
     * Call `/api/partner/v1` with a plain client-credentials token carrying the `sls:` scopes.
     *
     * @param string[]                $scopes
     * @param array<string, string>   $query
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function partnerCall(string $method, string $path, array $scopes, array $query = [], ?array $json = null): array
    {
        $options = [
            'auth_bearer'   => $this->serviceToken($scopes),
            'headers'       => ['Accept' => 'application/json'],
            'timeout'       => 5,
            'max_duration'  => 15,
            'max_redirects' => 0,
        ];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($method === 'POST') {
            $options['json'] = (object) ($json ?? []);
        }
        try {
            $response = $this->httpClient->request($method, $this->metadata->issuer() . '/api/partner/v1' . $path, $options);
            $status   = $response->getStatusCode();
            $answer   = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new SlsUnavailableException('SLS partner API failed: ' . $e->getMessage(), 0, $e);
        }
        if ($status >= 500) {
            throw new SlsUnavailableException(sprintf('SLS partner API answered HTTP %d.', $status));
        }
        if ($status !== 200) {
            throw new SlsPartnerException($status, array_map(
                static fn (mixed $e): string => is_array($e) ? (string) ($e['message'] ?? '') : (string) $e,
                (array) ($answer['errors'] ?? []),
            ));
        }

        return $answer;
    }

    /** The generation markers a sibling token's cache key carries, {@see self::forgetSiblingTokens()}. */
    private function siblingTokenGeneration(?string $organizationId, ?string $callerTenantId): string
    {
        $generations = new CacheGenerations($this->cache);
        $markers     = [];
        if ($callerTenantId !== null) {
            $markers[] = $generations->current('service_token.tenant.' . $callerTenantId);
        }
        if ($organizationId !== null) {
            $markers[] = $generations->current('service_token.org.' . $organizationId);
        }

        return $markers === [] ? $generations->current('service_token.any') : implode('.', $markers);
    }

    private function discoveryCacheKey(string $organizationId): string
    {
        return 'sls_connector.discovery.' . sha1($organizationId);
    }

    private function linksCacheKey(string $tenantId): string
    {
        return 'sls_connector.links.' . sha1($tenantId);
    }
}
