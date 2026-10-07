<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Jwt\KeySetProvider;
use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
use Smartlabsys\SlsConnectorBundle\Jwt\TokenValidator;
use Smartlabsys\SlsConnectorBundle\Security\PartnerTokenIntrospector;
use Smartlabsys\SlsConnectorBundle\Security\SlsAppUser;
use Smartlabsys\SlsConnectorBundle\Security\SlsIdentity;
use Smartlabsys\SlsConnectorBundle\Security\SlsUserResolverInterface;
use Smartlabsys\SlsConnectorBundle\Security\SlsUserTokenHandler;
use Smartlabsys\SlsConnectorBundle\Test\SlsTestTokens;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class SlsUserTokenHandlerTest extends TestCase
{
    private const ISS = 'https://sls.test';
    private const AUD = 'http://app.test';
    private const CID = 'demo-client';

    private SlsTestTokens $tokens;
    private TokenValidator $validator;
    private RequestStack $requestStack;
    private string $jwksFile;

    /** @var list<string> */
    private array $introspections = [];

    protected function setUp(): void
    {
        $this->tokens   = SlsTestTokens::shared();
        $this->jwksFile = (string) tempnam(sys_get_temp_dir(), 'sls-jwks');
        file_put_contents($this->jwksFile, json_encode($this->tokens->jwks()));
        $http               = new MockHttpClient();
        $cache              = new ArrayAdapter();
        $keys               = new KeySetProvider(new SlsMetadata($http, $cache, self::ISS), $http, $cache, $this->jwksFile);
        $this->validator    = new TokenValidator($keys, self::ISS, self::AUD, self::CID);
        $this->requestStack = new RequestStack();
        $this->requestStack->push(new Request());
    }

    protected function tearDown(): void
    {
        @unlink($this->jwksFile);
    }

    public function testUserToken(): void
    {
        $badge = $this->handler(false)->getUserBadgeFrom($this->tokens->userAccessToken(self::ISS, self::AUD, 'other-client', 'user-1'));

        self::assertSame('user:user-1', $badge->getUser()->getUserIdentifier());
    }

    public function testAppTokenRefusedByDefault(): void
    {
        $this->expectException(BadCredentialsException::class);
        $this->handler(false)->getUserBadgeFrom($this->tokens->appToken(self::ISS, self::AUD, 'lims-client', 'lims', 'tenant-1'));
    }

    public function testAppTokenAcceptedWhenEnabled(): void
    {
        $user = $this->handler(true)->getUserBadgeFrom($this->tokens->appToken(self::ISS, self::AUD, 'lims-client', 'lims', 'tenant-1', ['caller_tenant_id' => 'lims-tenant-9']))->getUser();

        self::assertInstanceOf(SlsAppUser::class, $user);
        self::assertSame('lims-client', $user->clientId);
        self::assertSame('lims', $user->app);
        self::assertSame('tenant-1', $user->tenantId);
        self::assertSame('lims-tenant-9', $user->callerTenantId);
        self::assertSame([SlsAppUser::ROLE], $user->getRoles());
        self::assertSame('tenant-1', $this->requestStack->getCurrentRequest()->attributes->get(SlsUserTokenHandler::CLAIMS_ATTRIBUTE)['tenant_id']);
    }

    public function testPartnerTokenCarriesThePartnership(): void
    {
        $user = $this->handler(true)->getUserBadgeFrom($this->tokens->partnerToken(self::ISS, self::AUD, ['qc:requests.read'], 'tenant-1', 'qc:laboratory', '11111111-1111-4111-8111-111111111111', 'lims-tenant-9', 'lims'))->getUser();

        self::assertInstanceOf(SlsAppUser::class, $user);
        self::assertSame('11111111-1111-4111-8111-111111111111', $user->partnershipId);
        self::assertSame('qc:laboratory', $user->partnershipRole);
        self::assertSame('lims-tenant-9', $user->callerTenantId);
        self::assertSame(['qc:requests.read'], $user->scopes);

        $linked = $this->handler(true)->getUserBadgeFrom($this->tokens->linkToken(self::ISS, self::AUD, [], 'tenant-1', null, 'lims', ['role' => 'x']))->getUser();
        self::assertNull($linked->partnershipId);
        self::assertNull($linked->partnershipRole, 'no role without a partnership');
    }

    public function testRevokedPartnerTokenIsRefused(): void
    {
        $handler = $this->handler(true, [new JsonMockResponse(['active' => false])]);

        try {
            $handler->getUserBadgeFrom($this->tokens->partnerToken(self::ISS, self::AUD, ['qc:requests.read'], 'tenant-1', 'qc:laboratory'));
            self::fail('Expected BadCredentialsException');
        } catch (BadCredentialsException $e) {
            self::assertSame('This partner token is no longer valid.', $e->getMessage());
        }
        self::assertCount(1, $this->introspections);
    }

    public function testPartnerTokenIsRefusedWhileSlsCannotBeAsked(): void
    {
        $handler = $this->handler(true, [new MockResponse('', ['error' => 'connection refused'])]);

        try {
            $handler->getUserBadgeFrom($this->tokens->partnerToken(self::ISS, self::AUD, ['qc:requests.read'], 'tenant-1', 'qc:laboratory'));
            self::fail('Expected BadCredentialsException');
        } catch (BadCredentialsException $e) {
            self::assertSame('The partner token could not be checked with SLS.', $e->getMessage(), 'fail closed');
        }
    }

    public function testActivePartnerTokenAndLinkTokens(): void
    {
        $handler = $this->handler(true, [new JsonMockResponse(['active' => true])]);

        $user = $handler->getUserBadgeFrom($this->tokens->partnerToken(self::ISS, self::AUD, [], 'tenant-1', 'qc:laboratory', 'p-1'))->getUser();
        self::assertSame('p-1', $user->partnershipId);
        $handler->getUserBadgeFrom($this->tokens->linkToken(self::ISS, self::AUD, [], 'tenant-1'));
        $handler->getUserBadgeFrom($this->tokens->userAccessToken(self::ISS, self::AUD, 'other-client', 'user-1'));

        self::assertCount(1, $this->introspections, 'only the partner token is introspected');
    }

    public function testExchangedUserTokenOverAPartnershipIsIntrospectedToo(): void
    {
        $this->expectException(BadCredentialsException::class);
        $this->handler(false, [new JsonMockResponse(['active' => false])])
            ->getUserBadgeFrom($this->tokens->userAccessToken(self::ISS, self::AUD, 'lims-client', 'user-1', ['partnership_id' => 'p-1', 'role' => 'qc:laboratory']));
    }

    public function testRefusedAppTokenIsNotIntrospected(): void
    {
        try {
            $this->handler(false, [])->getUserBadgeFrom($this->tokens->partnerToken(self::ISS, self::AUD, [], 'tenant-1', 'qc:laboratory'));
            self::fail('Expected BadCredentialsException');
        } catch (BadCredentialsException $e) {
            self::assertSame('App tokens are not accepted here.', $e->getMessage());
        }
        self::assertSame([], $this->introspections);
    }

    public function testServiceTokenAlwaysRefused(): void
    {
        $this->expectException(BadCredentialsException::class);
        $this->handler(true)->getUserBadgeFrom($this->tokens->serviceToken(self::ISS, self::AUD, [], 'tenant-1'));
    }

    /** @param list<MockResponse>|null $introspection SLS's introspection answers; null = no introspector */
    private function handler(bool $acceptAppTokens, ?array $introspection = null): SlsUserTokenHandler
    {
        $resolver = new class implements SlsUserResolverInterface {
            public function resolveOidcUser(SlsIdentity $identity): ?UserInterface
            {
                return null;
            }

            public function loadBySlsUserId(string $slsUserId, array $claims): ?UserInterface
            {
                return new InMemoryUser('user:' . $slsUserId, null);
            }
        };

        $introspector = null;
        if ($introspection !== null) {
            $http = new MockHttpClient(function (string $method, string $url) use (&$introspection): MockResponse {
                if (str_ends_with($url, '/.well-known/openid-configuration')) {
                    return new JsonMockResponse(['issuer' => self::ISS, 'introspection_endpoint' => self::ISS . '/oauth2/introspect']);
                }
                $this->introspections[] = $url;

                return array_shift($introspection) ?? throw new \LogicException('Unexpected request ' . $method . ' ' . $url);
            });
            $cache        = new ArrayAdapter();
            $introspector = new PartnerTokenIntrospector(new SlsClient($http, $cache, new SlsMetadata($http, $cache, self::ISS), self::CID, 's3cret'), $cache);
        }

        return new SlsUserTokenHandler($this->validator, $this->requestStack, $resolver, null, $acceptAppTokens, $introspector);
    }
}
