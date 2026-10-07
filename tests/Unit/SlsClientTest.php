<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Client\SlsEventException;
use Smartlabsys\SlsConnectorBundle\Client\SlsPartnerException;
use Smartlabsys\SlsConnectorBundle\Client\SlsTokenException;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
use Smartlabsys\SlsConnectorBundle\Provisioning\Model\SeedJob;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SlsClientTest extends TestCase
{
    private const ISS = 'https://sls.test';

    private const EVENTS_CONFIG = ['app' => ['key' => 'demo'], 'events' => ['emits' => ['demo.order.created'], 'consumes' => []]];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    public function testServiceTokenIsCached(): void
    {
        $client = $this->client([new JsonMockResponse(['access_token' => 'tok-1', 'expires_in' => 300])]);

        self::assertSame('tok-1', $client->serviceToken(['sls:read']));
        self::assertSame('tok-1', $client->serviceToken(['sls:read']));

        $tokenCalls = $this->requestsTo('/oauth2/token');
        self::assertCount(1, $tokenCalls);
        self::assertSame('POST', $tokenCalls[0]['method']);
        self::assertStringContainsString('grant_type=client_credentials', $tokenCalls[0]['options']['body']);
        self::assertStringContainsString('scope=sls%3Aread', $tokenCalls[0]['options']['body']);
        self::assertContains('Authorization: Basic ' . base64_encode('demo-client:s3cret'), $tokenCalls[0]['options']['headers']);
    }

    public function testTokenErrorBecomesSlsTokenException(): void
    {
        $client = $this->client([new JsonMockResponse(['error' => 'invalid_client', 'error_description' => 'nope'], ['http_code' => 401])]);

        try {
            $client->serviceToken();
            self::fail('Expected SlsTokenException');
        } catch (SlsTokenException $e) {
            self::assertSame('invalid_client', $e->error);
        }
    }

    public function testExchangeToken(): void
    {
        $client = $this->client([new JsonMockResponse(['access_token' => 'exchanged', 'expires_in' => 300])]);

        self::assertSame('exchanged', $client->exchangeToken('user-token', 'https://lims.test')['access_token']);
        $body = $this->requestsTo('/oauth2/token')[0]['options']['body'];
        self::assertStringContainsString('grant_type=' . rawurlencode(SlsClient::TOKEN_EXCHANGE_GRANT), $body);
        self::assertStringContainsString('subject_token=user-token', $body);
        self::assertStringContainsString('resource=' . rawurlencode('https://lims.test'), $body);
    }

    public function testConnectionsAreCachedAndForgotten(): void
    {
        $lims   = ['app' => 'lims', 'instance_id' => 'i1', 'tenant_id' => 't1', 'api_url' => 'https://lims.test/api', 'mcp_url' => null, 'audience' => 'https://lims.test', 'status' => 'active'];
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [$lims]]),
            new JsonMockResponse(['items' => []]),
        ]);

        self::assertSame($lims, $client->connection('org-1', 'lims'));
        self::assertSame($lims, $client->connection('org-1', 'lims'), 'served from cache');
        self::assertNull($client->connection('org-1', 'qc'));
        self::assertCount(1, $this->requestsTo('/api/discovery/organization/org-1/connections'));

        $client->forgetConnections('org-1');
        self::assertSame([], $client->connections('org-1'));
        self::assertCount(2, $this->requestsTo('/api/discovery/organization/org-1/connections'));
    }

    public function testCallSiblingUsesTokenForSiblingAudience(): void
    {
        $lims   = ['app' => 'lims', 'instance_id' => 'i1', 'tenant_id' => 't1', 'api_url' => 'https://lims.test/api/', 'mcp_url' => null, 'audience' => 'https://lims.test', 'status' => 'active'];
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [$lims]]),
            new JsonMockResponse(['access_token' => 'for-lims', 'expires_in' => 300]),
            new JsonMockResponse(['ok' => true]),
        ]);

        $response = $client->callSibling('org-1', 'lims', 'GET', '/samples');

        self::assertSame(['ok' => true], $response->toArray());
        $last = end($this->requests);
        self::assertSame('https://lims.test/api/samples', $last['url']);
        self::assertContains('Authorization: Bearer for-lims', $last['options']['headers']);
        $body = $this->requestsTo('/oauth2/token')[1]['options']['body'];
        self::assertStringContainsString('resource=' . rawurlencode('https://lims.test'), $body);
        self::assertStringContainsString('org_id=org-1', $body);
    }

    public function testCallSiblingPicksTheInstance(): void
    {
        $one = ['app' => 'lims', 'instance_id' => 'i1', 'instance_name' => 'LIMS', 'tenant_id' => 't1', 'api_url' => 'https://lims.test/api', 'mcp_url' => null, 'audience' => 'https://lims.test', 'status' => 'active'];
        $two = ['app' => 'lims', 'instance_id' => 'i2', 'instance_name' => 'LIMS 2', 'tenant_id' => 't2', 'api_url' => 'https://lims2.test/api', 'mcp_url' => null, 'audience' => 'https://lims2.test', 'status' => 'active'];
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [$one, $two]]),
            new JsonMockResponse(['access_token' => 'for-lims2', 'expires_in' => 300]),
            new JsonMockResponse(['ok' => true]),
        ]);

        self::assertSame($one, $client->connection('org-1', 'lims'), 'the first one without an instance');
        self::assertSame($two, $client->connection('org-1', 'lims', 'i2'));
        self::assertNull($client->connection('org-1', 'lims', 'i3'));

        $client->callSibling('org-1', 'lims', 'GET', '/samples', instanceId: 'i2');
        $last = end($this->requests);
        self::assertSame('https://lims2.test/api/samples', $last['url']);
        self::assertStringContainsString('resource=' . rawurlencode('https://lims2.test'), $this->requestsTo('/oauth2/token')[1]['options']['body']);
    }

    public function testSiblingServiceTokenNeedsOrganization(): void
    {
        $client = $this->client([]);

        $this->expectException(\InvalidArgumentException::class);
        $client->serviceToken([], 'https://lims.test');
    }

    public function testSiblingServiceTokenByTargetConnection(): void
    {
        $client = $this->client([new JsonMockResponse(['access_token' => 'for-qc', 'expires_in' => 300])]);

        self::assertSame('for-qc', $client->serviceToken([], null, null, 'conn-qc', 'tenant-a'));
        $body = $this->requestsTo('/oauth2/token')[0]['options']['body'];
        self::assertStringContainsString('target_connection=conn-qc', $body);
        self::assertStringContainsString('caller_tenant_id=tenant-a', $body);
        self::assertStringNotContainsString('org_id', $body);
        self::assertStringNotContainsString('resource', $body);
    }

    public function testCallerTenantOnlyForSiblingTokens(): void
    {
        $client = $this->client([new JsonMockResponse(['access_token' => 'own', 'expires_in' => 300])]);

        $client->serviceToken([], null, null, null, 'tenant-a');
        self::assertStringNotContainsString('caller_tenant_id', $this->requestsTo('/oauth2/token')[0]['options']['body']);
    }

    public function testExchangeTokenByTargetConnection(): void
    {
        $client = $this->client([new JsonMockResponse(['access_token' => 'exchanged', 'expires_in' => 300])]);

        $client->exchangeToken('user-token', null, null, 'conn-qc', 'tenant-a');
        $body = $this->requestsTo('/oauth2/token')[0]['options']['body'];
        self::assertStringContainsString('target_connection=conn-qc', $body);
        self::assertStringContainsString('caller_tenant_id=tenant-a', $body);
        self::assertStringNotContainsString('resource', $body);
    }

    public function testExchangeTokenNeedsATarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client([])->exchangeToken('user-token', null);
    }

    public function testLinksAreCachedPerTenantAndForgotten(): void
    {
        $qc     = self::linked('conn-qc', 'qc', 'https://qc.test');
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['org_id' => 'org-1', 'company_id' => 'c-a', 'items' => [$qc]]),
            new JsonMockResponse(['org_id' => 'org-1', 'company_id' => 'c-b', 'items' => []]),
            new JsonMockResponse(['org_id' => 'org-1', 'company_id' => 'c-a', 'items' => []]),
        ]);

        self::assertSame($qc, $client->link('tenant-a', 'conn-qc'));
        self::assertSame($qc, $client->link('tenant-a', 'qc'), 'by app key, from cache');
        self::assertNull($client->link('tenant-a', 'financial'));
        self::assertCount(1, $this->requestsTo('/api/discovery/tenant/tenant-a/links'));
        self::assertSame([], $client->links('tenant-b'), 'cached per tenant');

        $client->forgetLinks('tenant-a');
        self::assertNull($client->link('tenant-a', 'conn-qc'));
        self::assertCount(2, $this->requestsTo('/api/discovery/tenant/tenant-a/links'));
    }

    public function testCallLinkNamesBothEnds(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [self::linked('conn-qc', 'qc', 'https://qc.test')]]),
            new JsonMockResponse(['access_token' => 'for-qc', 'expires_in' => 300]),
            new JsonMockResponse(['ok' => true]),
        ]);

        $client->callLink('tenant-b', 'conn-qc', 'POST', '/requests', ['json' => ['x' => 1]]);

        $last = end($this->requests);
        self::assertSame('https://qc.test/api/requests', $last['url']);
        self::assertContains('Authorization: Bearer for-qc', $last['options']['headers']);
        $body = $this->requestsTo('/oauth2/token')[1]['options']['body'];
        self::assertStringContainsString('grant_type=client_credentials', $body);
        self::assertStringContainsString('target_connection=conn-qc', $body);
        self::assertStringContainsString('caller_tenant_id=tenant-b', $body);
    }

    public function testForgetSiblingTokensDropsThatTenantsLinkTokensOnly(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'a-1', 'expires_in' => 300]),
            new JsonMockResponse(['access_token' => 'b-1', 'expires_in' => 300]),
            new JsonMockResponse(['access_token' => 'plain', 'expires_in' => 300]),
            new JsonMockResponse(['access_token' => 'a-2', 'expires_in' => 300]),
        ]);

        self::assertSame('a-1', $client->serviceToken([], null, null, 'conn-qc', 'tenant-a'));
        self::assertSame('b-1', $client->serviceToken([], null, null, 'conn-qc', 'tenant-b'));
        self::assertSame('plain', $client->serviceToken([SlsClient::SCOPE_PARTNERSHIPS_READ]));
        self::assertSame('a-1', $client->serviceToken([], null, null, 'conn-qc', 'tenant-a'), 'cached');

        $client->forgetSiblingTokens('tenant-a', null);

        self::assertSame('a-2', $client->serviceToken([], null, null, 'conn-qc', 'tenant-a'), 'asked again');
        self::assertSame('b-1', $client->serviceToken([], null, null, 'conn-qc', 'tenant-b'), 'another tenant keeps its token');
        self::assertSame('plain', $client->serviceToken([SlsClient::SCOPE_PARTNERSHIPS_READ]), 'tokens for SLS itself are kept');
        self::assertCount(4, $this->requestsTo('/oauth2/token'));
    }

    public function testForgetSiblingTokensByOrgAndUntargeted(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'org-1', 'expires_in' => 300]),
            new JsonMockResponse(['access_token' => 'bare-1', 'expires_in' => 300]),
            new JsonMockResponse(['access_token' => 'org-2', 'expires_in' => 300]),
            new JsonMockResponse(['access_token' => 'bare-2', 'expires_in' => 300]),
        ]);

        self::assertSame('org-1', $client->serviceToken([], 'https://qc.test', 'org-x'));
        self::assertSame('bare-1', $client->serviceToken([], null, null, 'conn-qc'));

        $client->forgetSiblingTokens('some-tenant', 'org-y');
        self::assertSame('org-1', $client->serviceToken([], 'https://qc.test', 'org-x'), 'another org keeps its token');

        $client->forgetSiblingTokens(null, 'org-x');
        self::assertSame('org-2', $client->serviceToken([], 'https://qc.test', 'org-x'));
        self::assertSame('bare-2', $client->serviceToken([], null, null, 'conn-qc'), 'a token naming no tenant or org goes on every forget');
    }

    public function testIntrospect(): void
    {
        $client = $this->client([
            new JsonMockResponse(['active' => true, 'jti' => 'j-1']),
            new JsonMockResponse(['active' => false]),
            new JsonMockResponse(['error' => 'invalid_client'], ['http_code' => 401]),
            new JsonMockResponse(['unexpected' => true]),
        ], discovery: ['introspection_endpoint' => self::ISS . '/oauth2/introspect']);

        self::assertSame(['active' => true, 'jti' => 'j-1'], $client->introspect('tok'));
        self::assertSame(['active' => false], $client->introspect('tok'));
        $call = $this->requestsTo('/oauth2/introspect')[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('token=tok&token_type_hint=access_token', $call['options']['body']);
        self::assertContains('Authorization: Basic ' . base64_encode('demo-client:s3cret'), $call['options']['headers']);

        foreach (['invalid_client', 'HTTP 200'] as $expected) {
            try {
                $client->introspect('tok');
                self::fail('Expected SlsUnavailableException');
            } catch (SlsUnavailableException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testCallLinkForAUser(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [self::linked('conn-qc', 'qc', 'https://qc.test')]]),
            new JsonMockResponse(['access_token' => 'exchanged', 'expires_in' => 300]),
            new JsonMockResponse(['ok' => true]),
        ]);

        $client->callLink('tenant-b', 'qc', 'GET', '/requests', userAccessToken: 'user-token');

        self::assertContains('Authorization: Bearer exchanged', end($this->requests)['options']['headers']);
        $body = $this->requestsTo('/oauth2/token')[1]['options']['body'];
        self::assertStringContainsString('subject_token=user-token', $body);
        self::assertStringContainsString('target_connection=conn-qc', $body);
        self::assertStringContainsString('caller_tenant_id=tenant-b', $body);
    }

    public function testCallLinkWithoutLink(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => []]),
        ]);

        $this->expectException(SlsUnavailableException::class);
        $client->callLink('tenant-b', 'conn-qc', 'GET', '/requests');
    }

    public function testLinksOfAnInactiveConnectionAreEmptyAndCached(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['errors' => [['message' => 'Not found']]], ['http_code' => 404]),
        ]);

        self::assertSame([], $client->links('tenant-off'));
        self::assertSame([], $client->links('tenant-off'), 'from cache');
        self::assertNull($client->link('tenant-off', 'qc'));
        self::assertCount(1, $this->requestsTo('/api/discovery/tenant/tenant-off/links'));
    }

    public function testFailingLinksDiscoveryIsUnavailable(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse([], ['http_code' => 500]),
        ]);

        $this->expectException(SlsUnavailableException::class);
        $client->links('tenant-a');
    }

    public function testRefusedDiscoveryTokenIsUnavailable(): void
    {
        $client = $this->client([new JsonMockResponse(['error' => 'invalid_client'], ['http_code' => 401])]);

        try {
            $client->links('tenant-a');
            self::fail('expected SlsUnavailableException');
        } catch (SlsUnavailableException $e) {
            self::assertInstanceOf(SlsTokenException::class, $e->getPrevious());
        }
    }

    public function testLinksGrantingPreferTheOwnCompany(): void
    {
        $other = self::linked('conn-qc-b', 'qc', 'https://qc-b.test');
        $own   = ['same_company' => true] + self::linked('conn-qc-a', 'qc', 'https://qc-a.test');
        $off   = ['status' => 'disabled'] + self::linked('conn-qc-c', 'qc', 'https://qc-c.test');
        $fin   = self::linked('conn-fin', 'financial', 'https://fin.test');
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [$other, $off, $fin, $own]]),
        ]);

        self::assertSame([$own, $other], $client->linksGranting('tenant-a', 'qc:requests.write'));
        self::assertSame($own, $client->linkGranting('tenant-a', 'qc:requests.write'));
        self::assertNull($client->linkGranting('tenant-a', 'qc:requests.read'), 'a scope no link grants');
        self::assertSame([], $client->linksGranting('tenant-a', 'no-app-prefix'));
    }

    public function testPartnerLinksByRoleAndSide(): void
    {
        $lab      = ['partnership' => ['id' => 'p-1', 'role' => 'qc.laboratory', 'side' => 'customer'], 'link_id' => null, 'status' => 'inactive'] + self::linked('conn-lab', 'lims', 'https://lims.test');
        $customer = ['partnership' => ['id' => 'p-2', 'role' => 'qc.laboratory', 'side' => 'provider'], 'link_id' => null] + self::linked('conn-cust', 'qc', 'https://qc.test');
        $client   = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [$lab, $customer, self::linked('conn-fin', 'financial', 'https://fin.test')]]),
        ]);

        self::assertSame([$lab, $customer], $client->partnerLinks('tenant-a', 'qc.laboratory'));
        self::assertSame([$lab], $client->partnerLinks('tenant-a', 'qc.laboratory', 'customer'), 'whatever the connection status');
        self::assertSame([], $client->partnerLinks('tenant-a', 'financial.source'));
    }

    public function testCallLinkWithScopesFromTheInstanceRoot(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [self::linked('conn-qc', 'qc', 'https://qc.test')]]),
            new JsonMockResponse(['access_token' => 'narrow', 'expires_in' => 300]),
            new JsonMockResponse(['ok' => true]),
        ]);

        $client->callLink('tenant-b', 'conn-qc', 'GET', '/api/lab-integration/requests', scopes: ['qc:requests.read'], fromInstanceRoot: true);

        $last = end($this->requests);
        self::assertSame('https://qc.test/api/lab-integration/requests', $last['url']);
        self::assertContains('Authorization: Bearer narrow', $last['options']['headers']);
        $body = $this->requestsTo('/oauth2/token')[1]['options']['body'];
        self::assertStringContainsString('scope=qc%3Arequests.read', $body);
        self::assertStringContainsString('target_connection=conn-qc', $body);
        self::assertStringNotContainsString('resource=', $body);
    }

    public function testCallLinkForAUserWithScopes(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [self::linked('conn-qc', 'qc', 'https://qc.test')]]),
            new JsonMockResponse(['access_token' => 'exchanged', 'expires_in' => 300]),
            new JsonMockResponse(['ok' => true]),
        ]);

        $client->callLink('tenant-b', 'qc', 'GET', '/requests', userAccessToken: 'user-token', scopes: ['qc:requests.read', 'qc:requests.write']);

        $body = $this->requestsTo('/oauth2/token')[1]['options']['body'];
        self::assertStringContainsString('subject_token=user-token', $body);
        self::assertStringContainsString('scope=qc%3Arequests.read+qc%3Arequests.write', $body);
    }

    public function testSendEvent(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['status' => 'received']),
        ]);

        self::assertSame('received', $client->sendEvent('demo.happened', 'tenant-1', ['x' => 1], 'evt-1'));
        $call = $this->requestsTo('/api/webhook/cmd/receive')[0];
        self::assertSame('POST', $call['method']);
        self::assertContains('Authorization: Bearer svc', $call['options']['headers']);
        $body = json_decode($call['options']['body'], true);
        self::assertSame('evt-1', $body['event_id']);
        self::assertSame('demo.happened', $body['type']);
        self::assertSame('tenant-1', $body['tenant_id']);
        self::assertSame(['x' => 1], $body['data']);
        self::assertStringNotContainsString('org_id', $this->requestsTo('/oauth2/token')[0]['options']['body']);
    }

    public function testSeedCompletedUsesStableEventId(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['status' => 'received']),
            new JsonMockResponse(['status' => 'duplicate']),
        ]);
        $job = new SeedJob('job-1', SeedJob::STATUS_SUCCEEDED, ['users' => 2]);

        self::assertSame('received', $client->seedCompleted('tenant-1', $job));
        self::assertSame('duplicate', $client->seedCompleted('tenant-1', $job));
        [$first, $second] = array_map(
            static fn (array $r): array => json_decode($r['options']['body'], true),
            $this->requestsTo('/api/webhook/cmd/receive'),
        );
        self::assertSame($first['event_id'], $second['event_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first['event_id']);
        self::assertSame('seed.completed', $first['type']);
        self::assertSame(['job_id' => 'job-1', 'status' => 'succeeded', 'summary' => ['users' => 2]], array_intersect_key($first['data'], ['job_id' => 1, 'status' => 1, 'summary' => 1]));
    }

    public function testUserEvents(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['status' => 'received']),
            new JsonMockResponse(['status' => 'resynced']),
            new JsonMockResponse(['status' => 'received']),
        ]);
        $local   = new ScimUser(id: 'u1', userName: 'ana', givenName: 'Ana', email: 'ana@example.com', roles: ['qc:analyst']);
        $managed = new ScimUser(id: 'u2', userName: 'bob', familyName: 'Bob', active: false, slsUserId: 'b8a3f0a4-5f0e-4c6a-9d53-0e5e3c1f2a11', roles: ['qc:analyst']);

        self::assertSame('received', $client->userCreated('tenant-1', $local));
        self::assertSame('resynced', $client->userUpdated('tenant-1', $managed));
        self::assertSame('received', $client->userDeleted('tenant-1', 'u1'));
        [$created, $updated, $deleted] = array_map(
            static fn (array $r): array => json_decode($r['options']['body'], true),
            $this->requestsTo('/api/webhook/cmd/receive'),
        );
        self::assertSame('user.created', $created['type']);
        self::assertSame(['id' => 'u1', 'user_name' => 'ana', 'email' => 'ana@example.com', 'given_name' => 'Ana', 'active' => true], $created['data'], 'no roles for a local user');
        self::assertSame('user.updated', $updated['type']);
        self::assertSame(['id' => 'u2', 'user_name' => 'bob', 'family_name' => 'Bob', 'active' => false, 'sls_user_id' => 'b8a3f0a4-5f0e-4c6a-9d53-0e5e3c1f2a11', 'roles' => ['qc:analyst']], $updated['data']);
        self::assertSame(['type' => 'user.deleted', 'data' => ['id' => 'u1']], array_intersect_key($deleted, ['type' => 1, 'data' => 1]));
        self::assertNotSame($created['event_id'], $deleted['event_id']);
    }

    public function testUserEventNeedsId(): void
    {
        $client = $this->client([]);

        $this->expectException(\InvalidArgumentException::class);
        $client->userCreated('tenant-1', new ScimUser(userName: 'ana'));
    }

    public function testRefusedEventBecomesSlsEventException(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['errors' => [['message' => 'No connection.']]], ['http_code' => 404]),
        ]);

        try {
            $client->sendEvent('seed.completed', 'nope');
            self::fail('Expected SlsEventException');
        } catch (SlsEventException $e) {
            self::assertSame(404, $e->status);
            self::assertSame(['No connection.'], $e->errors);
        }
    }

    public function testFailingSlsOnEventIsUnavailable(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse([], ['http_code' => 503]),
        ]);

        $this->expectException(SlsUnavailableException::class);
        $client->sendEvent('seed.completed', 'tenant-1');
    }

    public function testCallSiblingWithoutConnection(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => []]),
        ]);

        $this->expectException(SlsUnavailableException::class);
        $client->callSibling('org-1', 'lims', 'GET', '/samples');
    }

    public function testEmitSendsADeclaredEvent(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['status' => 'received', 'recipients' => 1]),
            new JsonMockResponse(['status' => 'received', 'recipients' => 1]),
        ], self::EVENTS_CONFIG);

        $eventId = $client->emit('demo.order.created', 'tenant-1', ['order' => 'o-1']);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $eventId);
        $body = json_decode($this->requestsTo('/api/webhook/cmd/receive')[0]['options']['body'], true);
        self::assertSame($eventId, $body['event_id']);
        self::assertSame('demo.order.created', $body['type']);
        self::assertSame('tenant-1', $body['tenant_id']);
        self::assertSame(['order' => 'o-1'], $body['data']);

        self::assertSame('evt-7', $client->emit('demo.order.created', 'tenant-1', [], 'evt-7'), 'a given event id is kept');
    }

    public function testEmitHandsBackSlsAnswer(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['status' => 'duplicate']),
        ], self::EVENTS_CONFIG);

        self::assertSame('evt-8', $client->emit('demo.order.created', 'tenant-1', [], 'evt-8', $answer));
        self::assertSame('duplicate', $answer);
    }

    public function testCanEmit(): void
    {
        $client = $this->client([], self::EVENTS_CONFIG);

        self::assertTrue($client->canEmit('demo.order.created'));
        self::assertFalse($client->canEmit('demo.order.deleted'), 'not declared');
        self::assertFalse($client->canEmit('qc.order.created'), 'another app\'s prefix');
        self::assertFalse($this->client([])->canEmit('demo.order.created'), 'no app key configured');
    }

    public function testEmitNeedsTheAppPrefix(): void
    {
        $client = $this->client([], self::EVENTS_CONFIG);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with');
        $client->emit('qc.order.created', 'tenant-1');
    }

    public function testEmitNeedsADeclaredType(): void
    {
        $client = $this->client([], self::EVENTS_CONFIG);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('events.emits');
        $client->emit('demo.order.deleted', 'tenant-1');
    }

    public function testCallSiblingByConnection(): void
    {
        $one    = self::linked('conn-1', 'lims', 'https://lims.test');
        $two    = self::linked('conn-2', 'lims', 'https://lims2.test');
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [$one, $two]]),
            new JsonMockResponse(['access_token' => 'for-conn-2', 'expires_in' => 300]),
            new JsonMockResponse(['ok' => true]),
        ]);

        $client->callSibling('org-1', 'lims', 'GET', '/samples', connectionId: 'conn-2');

        $last = end($this->requests);
        self::assertSame('https://lims2.test/api/samples', $last['url']);
        self::assertContains('Authorization: Bearer for-conn-2', $last['options']['headers']);
        $body = $this->requestsTo('/oauth2/token')[1]['options']['body'];
        self::assertStringContainsString('target_connection=conn-2', $body);
        self::assertStringNotContainsString('resource', $body);
    }

    public function testCallSiblingByUnknownConnection(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [self::linked('conn-1', 'lims', 'https://lims.test')]]),
        ]);

        $this->expectException(SlsUnavailableException::class);
        $client->callSibling('org-1', 'lims', 'GET', '/samples', connectionId: 'conn-9');
    }

    public function testCallSiblingByConnectionAndTenantGoesOverTheLink(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'svc', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [self::linked('conn-qc', 'qc', 'https://qc.test')]]),
            new JsonMockResponse(['access_token' => 'for-qc', 'expires_in' => 300]),
            new JsonMockResponse(['ok' => true]),
        ]);

        $client->callSibling('org-1', 'qc', 'GET', '/requests', connectionId: 'conn-qc', tenantId: 'tenant-b');

        self::assertSame('https://qc.test/api/requests', end($this->requests)['url']);
        self::assertStringContainsString('caller_tenant_id=tenant-b', $this->requestsTo('/oauth2/token')[1]['options']['body']);
    }

    public function testDirectoryUsesAPlainTokenWithTheDirectoryScope(): void
    {
        $entry  = ['connection_id' => 'conn-lab', 'company_name' => 'Lab B', 'role' => 'qc:laboratory'];
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'dir', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [$entry]]),
        ]);

        self::assertSame([$entry], $client->directory('qc:laboratory', 'tenant-a'));
        $token = $this->requestsTo('/oauth2/token')[0]['options']['body'];
        self::assertStringContainsString('scope=sls%3Adirectory.read', $token);
        self::assertStringNotContainsString('target_connection', $token);
        self::assertStringNotContainsString('resource', $token);
        $call = $this->requestsTo('/api/partner/v1/directory')[0];
        self::assertSame('GET', $call['method']);
        self::assertContains('Authorization: Bearer dir', $call['options']['headers']);
        parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);
        self::assertSame(['role' => 'qc:laboratory', 'tenant_id' => 'tenant-a'], $query);
    }

    public function testPartnershipsList(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'rd', 'expires_in' => 300]),
            new JsonMockResponse(['items' => [['id' => 'p-1', 'status' => 'active']]]),
        ]);

        self::assertSame([['id' => 'p-1', 'status' => 'active']], $client->partnerships('tenant-a'));
        self::assertStringContainsString('scope=sls%3Apartnerships.read', $this->requestsTo('/oauth2/token')[0]['options']['body']);
    }

    public function testProposeAndRedeem(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'mg', 'expires_in' => 300]),
            new JsonMockResponse(['id' => 'p-1', 'status' => 'pending']),
            new JsonMockResponse(['id' => 'p-2', 'status' => 'active']),
            new JsonMockResponse(['id' => 'p-1', 'status' => 'ended']),
        ]);

        self::assertSame('pending', $client->proposePartnership('qc:laboratory', 'tenant-a', 'conn-lab')['status']);
        self::assertSame('active', $client->redeemInvite('ABCD-EFGH-JKLM', 'tenant-a')['status']);
        self::assertSame('ended', $client->endPartnership('p-1')['status']);

        self::assertStringContainsString('scope=sls%3Apartnerships.manage', $this->requestsTo('/oauth2/token')[0]['options']['body']);
        self::assertCount(1, $this->requestsTo('/oauth2/token'), 'one cached token');
        self::assertSame(['role' => 'qc:laboratory', 'tenant_id' => 'tenant-a', 'counterpart' => 'conn-lab'], json_decode($this->requestsTo('/api/partner/v1/partnerships')[0]['options']['body'], true));
        self::assertSame(['code' => 'ABCD-EFGH-JKLM', 'tenant_id' => 'tenant-a'], json_decode($this->requestsTo('/api/partner/v1/partnerships/redeem')[0]['options']['body'], true));
        self::assertSame('POST', $this->requestsTo('/api/partner/v1/partnerships/p-1/end')[0]['method']);
    }

    public function testRefusedPartnerCallBecomesSlsPartnerException(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'mg', 'expires_in' => 300]),
            new JsonMockResponse(['errors' => ['This invite code is not valid.']], ['http_code' => 400]),
        ]);

        try {
            $client->redeemInvite('NOPE', 'tenant-a');
            self::fail('Expected SlsPartnerException');
        } catch (SlsPartnerException $e) {
            self::assertSame(400, $e->status);
            self::assertSame(['This invite code is not valid.'], $e->errors);
        }
    }

    public function testFailingPartnerApiIsUnavailable(): void
    {
        $client = $this->client([
            new JsonMockResponse(['access_token' => 'rd', 'expires_in' => 300]),
            new MockResponse('', ['http_code' => 502]),
        ]);

        $this->expectException(SlsUnavailableException::class);
        $client->partnerships('tenant-a');
    }

    public function testUnreachableSls(): void
    {
        $client = $this->client([new MockResponse('', ['error' => 'Connection refused'])]);

        $this->expectException(SlsUnavailableException::class);
        $client->serviceToken();
    }

    /** @return array<string, mixed> one item of the tenant links answer */
    private static function linked(string $connectionId, string $app, string $base): array
    {
        return [
            'app' => $app, 'connection_id' => $connectionId, 'instance_id' => 'i-' . $app, 'tenant_id' => 't-' . $app,
            'company_id' => 'c-a', 'company_name' => 'Lab A', 'api_url' => $base . '/api', 'mcp_url' => null,
            'audience' => $base, 'status' => 'active', 'link_id' => 'l-1', 'scopes' => [$app . ':requests.write'],
            'declared' => true, 'same_company' => false,
        ];
    }

    /**
     * @param list<MockResponse>   $responses
     * @param array<string, mixed> $config    the bundle config, as `%sls_connector.config%`
     */
    private function client(array $responses, array $config = [], array $discovery = []): SlsClient
    {
        $this->responses = $responses;
        $discovery      += ['issuer' => self::ISS, 'token_endpoint' => self::ISS . '/oauth2/token', 'jwks_uri' => self::ISS . '/oauth2/jwks'];
        $http            = new MockHttpClient(function (string $method, string $url, array $options) use ($discovery): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            if (str_ends_with($url, '/.well-known/openid-configuration')) {
                return new JsonMockResponse($discovery);
            }

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request ' . $method . ' ' . $url);
        });
        $cache = new ArrayAdapter();

        return new SlsClient($http, $cache, new SlsMetadata($http, $cache, self::ISS), 'demo-client', 's3cret', $config);
    }

    /** @return list<array{method: string, url: string, options: array<string, mixed>}> */
    private function requestsTo(string $path): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => parse_url($r['url'], PHP_URL_PATH) === $path));
    }
}
