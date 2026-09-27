<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Jwt\InvalidTokenException;
use Smartlabsys\SlsConnectorBundle\Jwt\KeySetProvider;
use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
use Smartlabsys\SlsConnectorBundle\Jwt\TokenValidator;
use Smartlabsys\SlsConnectorBundle\Test\SlsTestTokens;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class TokenValidatorTest extends TestCase
{
    private const ISS = 'https://sls.test';
    private const AUD = 'http://app.test';
    private const CID = 'demo-client';

    private SlsTestTokens $tokens;
    private TokenValidator $validator;
    private string $jwksFile;

    protected function setUp(): void
    {
        $this->tokens   = SlsTestTokens::shared();
        $this->jwksFile = (string) tempnam(sys_get_temp_dir(), 'sls-jwks');
        file_put_contents($this->jwksFile, json_encode($this->tokens->jwks()));
        $http            = new MockHttpClient();
        $cache           = new ArrayAdapter();
        $keys            = new KeySetProvider(new SlsMetadata($http, $cache, self::ISS), $http, $cache, $this->jwksFile);
        $this->validator = new TokenValidator($keys, self::ISS, self::AUD, self::CID);
    }

    protected function tearDown(): void
    {
        @unlink($this->jwksFile);
    }

    public function testAccessToken(): void
    {
        $claims = $this->validator->validateAccessToken($this->tokens->serviceToken(self::ISS, self::AUD, ['sls:scim'], 'tenant-1'));

        self::assertSame('sls', $claims['sub']);
        self::assertSame('tenant-1', $claims['tenant_id']);
        self::assertSame('sls:scim', $claims['scope']);
    }

    public function testAccessTokenRejections(): void
    {
        $this->assertRejected(fn () => $this->validator->validateAccessToken('a.b'), 'malformed');
        $this->assertRejected(fn () => $this->validator->validateAccessToken($this->tokens->serviceToken(self::ISS, 'http://other.test', [])), 'aud');
        $this->assertRejected(fn () => $this->validator->validateAccessToken($this->tokens->serviceToken('https://evil.test', self::AUD, [])), 'iss');
        $this->assertRejected(fn () => $this->validator->validateAccessToken($this->tokens->serviceToken(self::ISS, self::AUD, [], null, [], ['typ' => 'JWT'])), 'typ');
        $this->assertRejected(fn () => $this->validator->validateAccessToken($this->tokens->serviceToken(self::ISS, self::AUD, [], null, ['exp' => time() - 31])), 'expired');
        $this->assertRejected(fn () => $this->validator->validateAccessToken($this->tokens->serviceToken(self::ISS, self::AUD, [], null, ['exp' => null])), 'no exp');
        $this->assertRejected(fn () => $this->validator->validateAccessToken(SlsTestTokens::generate()->serviceToken(self::ISS, self::AUD, [])), 'unknown kid');
        $this->assertRejected(fn () => $this->validator->validateAccessToken($this->tokens->logoutToken(self::ISS, self::AUD, 'u', 's')), 'logout token as access token');
    }

    public function testAccessTokenWithinLeeway(): void
    {
        $claims = $this->validator->validateAccessToken($this->tokens->serviceToken(self::ISS, self::AUD, [], null, ['exp' => time() - 10]));
        self::assertSame('sls', $claims['sub']);
    }

    public function testIdToken(): void
    {
        $claims = $this->validator->validateIdToken($this->tokens->idToken(self::ISS, self::CID, 'user-1', 'n0nce'), 'n0nce');
        self::assertSame('user-1', $claims['sub']);

        $this->assertRejected(fn () => $this->validator->validateIdToken($this->tokens->idToken(self::ISS, self::CID, 'user-1', 'n0nce'), 'other'), 'nonce');
        $this->assertRejected(fn () => $this->validator->validateIdToken($this->tokens->idToken(self::ISS, 'other-client', 'user-1', 'n0nce'), 'n0nce'), 'aud');
        $this->assertRejected(fn () => $this->validator->validateIdToken($this->tokens->idToken(self::ISS, self::CID, 'user-1', 'n0nce', ['azp' => 'other']), 'n0nce'), 'azp');
    }

    public function testLogoutToken(): void
    {
        $claims = $this->validator->validateLogoutToken($this->tokens->logoutToken(self::ISS, self::CID, null, 'sid-1'));
        self::assertSame('sid-1', $claims['sid']);

        $this->assertRejected(fn () => $this->validator->validateLogoutToken($this->tokens->logoutToken(self::ISS, self::CID, 'u', 's', ['nonce' => 'x'])), 'nonce');
        $this->assertRejected(fn () => $this->validator->validateLogoutToken($this->tokens->logoutToken(self::ISS, self::CID, 'u', 's', ['events' => null])), 'events');
        $this->assertRejected(fn () => $this->validator->validateLogoutToken($this->tokens->logoutToken(self::ISS, self::CID, null, null)), 'no sub/sid');
        $this->assertRejected(fn () => $this->validator->validateLogoutToken($this->tokens->logoutToken(self::ISS, 'other', 'u', 's')), 'aud');
        $this->assertRejected(fn () => $this->validator->validateLogoutToken($this->tokens->serviceToken(self::ISS, self::CID, [])), 'access token as logout token');
    }

    private function assertRejected(callable $fn, string $case): void
    {
        try {
            $fn();
            self::fail('Accepted: ' . $case);
        } catch (InvalidTokenException) {
            $this->addToAssertionCount(1);
        }
    }
}
