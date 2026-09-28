<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Client\SlsEventException;
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

    public function testSiblingServiceTokenNeedsOrganization(): void
    {
        $client = $this->client([]);

        $this->expectException(\InvalidArgumentException::class);
        $client->serviceToken([], 'https://lims.test');
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

    public function testUnreachableSls(): void
    {
        $client = $this->client([new MockResponse('', ['error' => 'Connection refused'])]);

        $this->expectException(SlsUnavailableException::class);
        $client->serviceToken();
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): SlsClient
    {
        $this->responses = $responses;
        $discovery       = ['issuer' => self::ISS, 'token_endpoint' => self::ISS . '/oauth2/token', 'jwks_uri' => self::ISS . '/oauth2/jwks'];
        $http            = new MockHttpClient(function (string $method, string $url, array $options) use ($discovery): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            if (str_ends_with($url, '/.well-known/openid-configuration')) {
                return new JsonMockResponse($discovery);
            }

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request ' . $method . ' ' . $url);
        });
        $cache = new ArrayAdapter();

        return new SlsClient($http, $cache, new SlsMetadata($http, $cache, self::ISS), 'demo-client', 's3cret');
    }

    /** @return list<array{method: string, url: string, options: array<string, mixed>}> */
    private function requestsTo(string $path): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => parse_url($r['url'], PHP_URL_PATH) === $path));
    }
}
