<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Contract;

use Smartlabsys\SlsConnectorBundle\Security\OidcLoginFlow;
use Smartlabsys\SlsConnectorBundle\Test\SlsTestTokens;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Smartlabsys\SlsConnectorBundle\Tests\App\Sls\MockSlsResponses;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * The browser-facing parts, with SLS mocked: "Sign in with Smartlab", back-channel logout
 * killing the session, RP-initiated logout, and SLS user tokens on /api.
 */
final class DemoAppSecurityTest extends WebTestCase
{
    private const ISSUER    = 'https://sls.test';
    private const CLIENT_ID = 'demo-client';
    private const AUDIENCE  = 'http://localhost';

    private SlsTestTokens $tokens;

    protected function setUp(): void
    {
        $this->tokens = SlsTestTokens::shared();
    }

    public function testSignInWithSmartlabThenBackchannelLogout(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->installKeys();
        $sub = 'sls-' . bin2hex(random_bytes(4));
        $sid = 'sid-' . bin2hex(random_bytes(4));

        $client->request('GET', '/login');
        self::assertSelectorTextContains('#sls-login', 'Sign in with Smartlab');

        $client->request('GET', '/sls/oidc/login?target=/account');
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::ISSUER . '/oauth2/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame(self::CLIENT_ID, $query['client_id']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(self::AUDIENCE . '/sls/oidc/callback', $query['redirect_uri']);

        $idToken = $this->tokens->idToken(self::ISSUER, self::CLIENT_ID, $sub, $query['nonce'], [
            'sid' => $sid, 'email' => $sub . '@example.com', 'email_verified' => true, 'name' => 'Demo Person',
        ]);
        $tokenRequests = [];
        $this->mockSls([
            'token' => static function (string $method, string $url, array $options) use ($idToken, &$tokenRequests): JsonMockResponse {
                $tokenRequests[] = $options['body'];

                return new JsonMockResponse(['access_token' => 'at', 'token_type' => 'Bearer', 'expires_in' => 300, 'id_token' => $idToken]);
            },
        ]);

        $client->request('GET', '/sls/oidc/callback?code=abc&state=' . rawurlencode($query['state']));
        self::assertResponseRedirects('/account');
        self::assertStringContainsString('code_verifier=', $tokenRequests[0]);
        $client->followRedirect();
        self::assertSelectorTextContains('#signed-in', $sub . '@example.com');

        // Replaying the callback (state consumed) fails.
        $client->request('GET', '/sls/oidc/callback?code=abc&state=' . rawurlencode($query['state']));
        self::assertResponseRedirects('/login');

        $client->request('GET', '/');
        self::assertSelectorExists('#signed-in');

        $client->request('POST', '/sls/oidc/backchannel-logout', [
            'logout_token' => $this->tokens->logoutToken(self::ISSUER, self::CLIENT_ID, $sub, $sid),
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/');
        self::assertSelectorExists('#signed-out');
    }

    public function testCallbackErrorsAndRpLogout(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->installKeys();

        $client->request('GET', '/sls/oidc/login');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);

        $client->request('GET', '/sls/oidc/callback?error=access_denied&state=' . rawurlencode($query['state']));
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('#login-error', 'cancelled');

        // A wrong nonce is refused.
        $client->request('GET', '/sls/oidc/login');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        $bad = $this->tokens->idToken(self::ISSUER, self::CLIENT_ID, 'sub-x', 'other-nonce');
        $this->mockSls(['token' => static fn (): JsonMockResponse => new JsonMockResponse(['access_token' => 'at', 'expires_in' => 300, 'id_token' => $bad])]);
        $client->request('GET', '/sls/oidc/callback?code=abc&state=' . rawurlencode($query['state']));
        self::assertResponseRedirects('/login');

        // Good login, then local logout redirects to SLS end_session.
        $client->request('GET', '/sls/oidc/login');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        $good = $this->tokens->idToken(self::ISSUER, self::CLIENT_ID, 'sub-rp-' . bin2hex(random_bytes(3)), $query['nonce'], ['sid' => 's1', 'email' => 'rp@example.com', 'email_verified' => true]);
        $this->mockSls(['token' => static fn (): JsonMockResponse => new JsonMockResponse(['access_token' => 'at', 'expires_in' => 300, 'id_token' => $good])]);
        $client->request('GET', '/sls/oidc/callback?code=abc&state=' . rawurlencode($query['state']));
        self::assertResponseRedirects('/');

        $client->request('GET', '/logout');
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::ISSUER . '/oauth2/logout?', $location);
        self::assertStringContainsString('id_token_hint=', $location);
        self::assertStringContainsString('post_logout_redirect_uri=' . rawurlencode(self::AUDIENCE . '/'), $location);
    }

    public function testApiAcceptsSlsUserTokens(): void
    {
        $client = static::createClient();
        $this->installKeys();
        $sub   = 'sls-api-' . bin2hex(random_bytes(4));
        static::getContainer()->get(JsonStore::class)->update(static function (array &$data) use ($sub): void {
            $data['users']['u-' . $sub] = ['id' => 'u-' . $sub, 'email' => $sub . '@example.com', 'name' => null, 'sls_sub' => $sub];
        });

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokens->userAccessToken(self::ISSUER, self::AUDIENCE, self::CLIENT_ID, $sub)]);
        self::assertResponseIsSuccessful();
        self::assertSame($sub, json_decode((string) $client->getResponse()->getContent(), true)['sls_sub']);

        $cases = [
            'service token' => $this->tokens->serviceToken(self::ISSUER, self::AUDIENCE, ['sls:health']),
            'unknown user'  => $this->tokens->userAccessToken(self::ISSUER, self::AUDIENCE, self::CLIENT_ID, 'nobody'),
            'wrong aud'     => $this->tokens->userAccessToken(self::ISSUER, 'https://qc.example.com', self::CLIENT_ID, $sub),
        ];
        foreach ($cases as $case => $token) {
            $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            self::assertResponseStatusCodeSame(401, $case);
        }
    }

    private function installKeys(): void
    {
        $file = static::getContainer()->getParameter('sls_connector.jwks_file');
        @mkdir(dirname($file), 0o777, true);
        file_put_contents($file, json_encode($this->tokens->jwks()));
    }

    private function mockSls(array $handlers): void
    {
        MockSlsResponses::$token = $handlers['token'];
    }

    protected function tearDown(): void
    {
        MockSlsResponses::$token = null;
        parent::tearDown();
    }
}
