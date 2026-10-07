<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Contract;

use Smartlabsys\SlsConnectorBundle\Test\SlsTestTokens;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;
use Smartlabsys\SlsConnectorBundle\Webhook\WebhookSignature;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * 0.3 receiving side, on the demo app: brokered events (`SlsAppEvent`), `company.updated` through
 * `CompanyUpdatedHandlerInterface`, and `#[SlsSibling]` with the tenant resolver.
 */
final class DemoAppEventsTest extends WebTestCase
{
    private const ISSUER   = 'https://sls.test';
    private const AUDIENCE = 'http://localhost';
    private const SECRET   = 'demo-webhook-secret';

    private KernelBrowser $client;
    private SlsTestTokens $tokens;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tokens = SlsTestTokens::shared();
        $file         = static::getContainer()->getParameter('sls_connector.jwks_file');
        @mkdir(dirname($file), 0o777, true);
        file_put_contents($file, json_encode($this->tokens->jwks()));
    }

    public function testConsumedAppEventIsDispatched(): void
    {
        $tenant  = $this->tenant();
        $eventId = 'evt-' . bin2hex(random_bytes(6));
        $source  = ['connection_id' => 'conn-d2', 'app' => 'demo2', 'instance_id' => 'inst-d2', 'company_id' => 'co-b', 'company_name' => 'Lab B', 'tenant_id' => 'd2-tenant'];

        self::assertSame([200, 'received'], $this->webhook(['event_id' => $eventId, 'type' => 'demo2.order.created', 'tenant_id' => $tenant, 'data' => ['order' => 'o-1'], 'source' => $source + ['extra' => 'dropped']]));

        $logged = $this->appEvents($eventId);
        self::assertCount(1, $logged);
        self::assertSame('demo2.order.created', $logged[0]['type']);
        self::assertSame($tenant, $logged[0]['tenant_id']);
        self::assertSame(['order' => 'o-1'], $logged[0]['data']);
        self::assertSame($source, $logged[0]['source'], 'the known source fields only');
        self::assertSame($source + ['extra' => 'dropped'], $logged[0]['envelope']['source'], 'the envelope as delivered');
        self::assertSame($eventId, $logged[0]['envelope']['event_id']);

        self::assertSame([200, 'duplicate'], $this->webhook(['event_id' => $eventId, 'type' => 'demo2.order.created', 'tenant_id' => $tenant, 'data' => [], 'source' => $source]));
        self::assertCount(1, $this->appEvents($eventId), 'a redelivery is not dispatched again');
    }

    public function testUnconsumedAppEventIsAcknowledgedOnly(): void
    {
        $eventId = 'evt-' . bin2hex(random_bytes(6));

        self::assertSame([200, 'received'], $this->webhook(['event_id' => $eventId, 'type' => 'qc.request.created', 'tenant_id' => $this->tenant(), 'data' => [], 'source' => ['app' => 'qc', 'connection_id' => 'c']]));
        self::assertSame([], $this->appEvents($eventId));
    }

    public function testPlainWebhookOfAConsumedTypeIsNotAnAppEvent(): void
    {
        $eventId = 'evt-' . bin2hex(random_bytes(6));

        self::assertSame([200, 'received'], $this->webhook(['event_id' => $eventId, 'type' => 'demo2.order.created', 'tenant_id' => $this->tenant(), 'data' => []]));
        self::assertSame([], $this->appEvents($eventId), 'no source, no SlsAppEvent');
    }

    public function testCompanyUpdatedReachesTheHandler(): void
    {
        $tenant  = $this->tenant();
        $company = ['name' => 'Lab A d.o.o.', 'tax_id' => '100200300', 'city' => 'Novi Sad', 'country' => 'RS'];

        self::assertSame([200, 'received'], $this->webhook(['event_id' => 'evt-' . bin2hex(random_bytes(6)), 'type' => 'company.updated', 'tenant_id' => $tenant, 'data' => ['company' => $company]]));

        $row = static::getContainer()->get(JsonStore::class)->read()['tenants'][$tenant];
        self::assertSame('Lab A d.o.o.', $row['name']);
        self::assertSame('100200300', $row['company']['tax_id']);
        self::assertSame('Novi Sad', $row['company']['city']);

        self::assertSame([200, 'received'], $this->webhook(['event_id' => 'evt-' . bin2hex(random_bytes(6)), 'type' => 'company.updated', 'tenant_id' => $tenant, 'data' => ['company' => ['tax_id' => 'x']]]));
        self::assertSame('Lab A d.o.o.', static::getContainer()->get(JsonStore::class)->read()['tenants'][$tenant]['name'], 'no name, no call');
    }

    public function testSlsSiblingChecksScopeAndResolvesTheTenant(): void
    {
        $tenant = $this->tenant();

        $this->get('/api/sibling/orders', $this->tokens->linkToken(self::ISSUER, self::AUDIENCE, ['demo:orders.read'], $tenant, 'd2-tenant', 'demo2'));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($tenant, $body['tenant']['tenant_id']);

        $this->get('/api/sibling/orders', $this->tokens->linkToken(self::ISSUER, self::AUDIENCE, ['demo:orders.write'], $tenant, null, 'demo2'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->get('/api/sibling/orders', $this->tokens->linkToken(self::ISSUER, self::AUDIENCE, ['demo:orders.read'], 'tn_missing', null, 'demo2'));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'tenant_not_found'], json_decode((string) $this->client->getResponse()->getContent(), true));

        static::getContainer()->get(JsonStore::class)->update(static function (array &$data) use ($tenant): void {
            $data['tenants'][$tenant]['status'] = 'suspended';
        });
        $this->get('/api/sibling/orders', $this->tokens->linkToken(self::ISSUER, self::AUDIENCE, ['demo:orders.read'], $tenant, null, 'demo2'));
        self::assertResponseStatusCodeSame(404, 'the resolver decides: suspended tenants are not served');
    }

    public function testSlsSiblingLetsUsersThrough(): void
    {
        $sub = 'sls-sib-' . bin2hex(random_bytes(4));
        static::getContainer()->get(JsonStore::class)->update(static function (array &$data) use ($sub): void {
            $data['users']['u-' . $sub] = ['id' => 'u-' . $sub, 'email' => $sub . '@example.com', 'name' => null, 'sls_sub' => $sub];
        });

        $this->get('/api/sibling/orders', $this->tokens->userAccessToken(self::ISSUER, self::AUDIENCE, 'demo-client', $sub));
        self::assertResponseIsSuccessful();
        self::assertNull(json_decode((string) $this->client->getResponse()->getContent(), true)['tenant']);
    }

    private function tenant(): string
    {
        $id = 'tn_' . bin2hex(random_bytes(6));
        static::getContainer()->get(JsonStore::class)->update(static function (array &$data) use ($id): void {
            $data['tenants'][$id] = ['tenant_id' => $id, 'sls_org_id' => 'org-1', 'sls_company_id' => 'co-a', 'name' => 'Lab A', 'status' => 'active'];
        });

        return $id;
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{0: int, 1: ?string}
     */
    private function webhook(array $event): array
    {
        $body = json_encode($event + ['occurred_at' => gmdate(DATE_ATOM), 'org_id' => 'org-1'], JSON_THROW_ON_ERROR);
        $now  = time();
        $this->client->request('POST', '/sls/webhooks', [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X_SLS_TIMESTAMP' => (string) $now,
            'HTTP_X_SLS_SIGNATURE' => WebhookSignature::sign(self::SECRET, $now, $body),
        ], $body);
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['status'] ?? null];
    }

    /** @return list<array<string, mixed>> */
    private function appEvents(string $eventId): array
    {
        $events = static::getContainer()->get(JsonStore::class)->read()['app_events'] ?? [];

        return array_values(array_filter($events, static fn (array $e): bool => $e['event_id'] === $eventId));
    }
}
