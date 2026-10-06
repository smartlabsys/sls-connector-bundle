<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Jwt\KeySetProvider;
use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
use Smartlabsys\SlsConnectorBundle\Jwt\TokenValidator;
use Smartlabsys\SlsConnectorBundle\Security\SlsAppUser;
use Smartlabsys\SlsConnectorBundle\Security\SlsIdentity;
use Smartlabsys\SlsConnectorBundle\Security\SlsUserResolverInterface;
use Smartlabsys\SlsConnectorBundle\Security\SlsUserTokenHandler;
use Smartlabsys\SlsConnectorBundle\Test\SlsTestTokens;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
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

    public function testServiceTokenAlwaysRefused(): void
    {
        $this->expectException(BadCredentialsException::class);
        $this->handler(true)->getUserBadgeFrom($this->tokens->serviceToken(self::ISS, self::AUD, [], 'tenant-1'));
    }

    private function handler(bool $acceptAppTokens): SlsUserTokenHandler
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

        return new SlsUserTokenHandler($this->validator, $this->requestStack, $resolver, null, $acceptAppTokens);
    }
}
