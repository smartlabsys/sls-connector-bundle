<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Client\SlsTokenException;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
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
        self::assertStringContainsString('resource=' . rawurlencode('https://lims.test'), $this->requestsTo('/oauth2/token')[1]['options']['body']);
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
