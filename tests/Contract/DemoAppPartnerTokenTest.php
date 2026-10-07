<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Contract;

use Smartlabsys\SlsConnectorBundle\Security\PartnerTokenIntrospector;
use Smartlabsys\SlsConnectorBundle\Test\SlsTestTokens;
use Smartlabsys\SlsConnectorBundle\Tests\App\Sls\MockSlsResponses;
use Smartlabsys\SlsConnectorBundle\Webhook\WebhookSignature;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * 0.3.3 receiving side, on the demo app: partner tokens are checked with SLS's introspection,
 * the answer is cached and dropped on `partnership.*` webhooks, and SLS being down refuses them.
 */
final class DemoAppPartnerTokenTest extends WebTestCase
{
    private const ISSUER   = 'https://sls.test';
    private const AUDIENCE = 'http://localhost';
    private const SECRET   = 'demo-webhook-secret';

    private KernelBrowser $client;
    private SlsTestTokens $tokens;

    /** @var list<string> tokens SLS was asked about */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tokens = SlsTestTokens::shared();
        $file         = static::getContainer()->getParameter('sls_connector.jwks_file');
        @mkdir(dirname($file), 0o777, true);
        file_put_contents($file, json_encode($this->tokens->jwks()));
    }

    protected function tearDown(): void
    {
        MockSlsResponses::$introspect = null;
        parent::tearDown();
    }

    public function testPartnershipEndedRevokesAcceptedToken(): void
    {
        $active = true;
        $this->introspect(function () use (&$active): MockResponse {
            return new JsonMockResponse(['active' => $active]);
        });
        $token = $this->partnerToken();

        $this->ping($token);
        self::assertResponseIsSuccessful();
        $this->ping($token);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->asked, 'the answer is cached');
        self::assertStringContainsString('token=' . $token, $this->asked[0]);

        // SLS ends the partnership: it revokes the token and tells this app.
        $active = false;
        self::assertSame(200, $this->webhook('partnership.ended'));
        $this->ping($token);
        self::assertResponseStatusCodeSame(401);
        self::assertCount(2, $this->asked, 'the webhook dropped the cached answer');
    }

    public function testSlsDownRefusesPartnerTokensButNotLinkTokens(): void
    {
        $this->introspect(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        $this->ping($this->partnerToken());
        self::assertResponseStatusCodeSame(401, 'fail closed');

        $this->ping($this->tokens->linkToken(self::ISSUER, self::AUDIENCE, ['demo:orders.read'], 'tn-1', 'd2-tenant', 'demo2'));
        self::assertResponseIsSuccessful('same-org link tokens are validated locally');
        self::assertCount(1, $this->asked);
    }

    public function testRememberedAnswerNeedsNoSls(): void
    {
        $this->introspect(static fn (): MockResponse => throw new \LogicException('SLS must not be asked'));
        $token = $this->partnerToken();
        static::getContainer()->get(PartnerTokenIntrospector::class)->remember($token, true);

        $this->ping($token);
        self::assertResponseIsSuccessful();
        self::assertSame('app', json_decode((string) $this->client->getResponse()->getContent(), true)['caller']);
    }

    private function partnerToken(): string
    {
        return $this->tokens->partnerToken(self::ISSUER, self::AUDIENCE, ['demo:orders.read'], 'tn-1', 'demo:laboratory', null, 'd2-tenant', 'demo2');
    }

    /** @param callable(): MockResponse $answer */
    private function introspect(callable $answer): void
    {
        MockSlsResponses::$introspect = function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->asked[] = (string) $options['body'];

            return $answer();
        };
    }

    private function ping(string $token): void
    {
        $this->client->request('GET', '/api/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json']);
    }

    private function webhook(string $type): int
    {
        $body = json_encode([
            'event_id'    => 'evt-' . bin2hex(random_bytes(6)),
            'type'        => $type,
            'occurred_at' => gmdate(DATE_ATOM),
            'org_id'      => 'org-1',
            'tenant_id'   => 'tn-1',
            'data'        => ['partnership_id' => 'p-1', 'role' => 'demo:laboratory', 'side' => 'customer', 'status' => 'ended'],
        ], JSON_THROW_ON_ERROR);
        $now = time();
        $this->client->request('POST', '/sls/webhooks', [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X_SLS_TIMESTAMP' => (string) $now,
            'HTTP_X_SLS_SIGNATURE' => WebhookSignature::sign(self::SECRET, $now, $body),
        ], $body);

        return $this->client->getResponse()->getStatusCode();
    }
}
