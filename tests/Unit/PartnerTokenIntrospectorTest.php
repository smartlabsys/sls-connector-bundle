<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\InvalidTokenException;
use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
use Smartlabsys\SlsConnectorBundle\Security\PartnerTokenIntrospector;
use Smartlabsys\SlsConnectorBundle\Test\SlsTestTokens;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PartnerTokenIntrospectorTest extends TestCase
{
    private const ISS = 'https://sls.test';

    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $introspections = [];

    /** @var list<MockResponse|\Throwable> */
    private array $answers = [];

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    public function testActiveAnswerIsCachedPerToken(): void
    {
        $introspector = $this->introspector([new JsonMockResponse(['active' => true, 'jti' => 'jti-1'])]);
        [$token, $claims] = $this->partnerToken('jti-1');

        $introspector->assertActive($token, $claims);
        $introspector->assertActive($token, $claims);

        self::assertCount(1, $this->introspections, 'the second check comes from the cache');
        self::assertSame(self::ISS . '/oauth2/introspect', $this->introspections[0]['url']);
        self::assertStringContainsString('token=' . $token, $this->introspections[0]['options']['body']);
        self::assertStringContainsString('token_type_hint=access_token', $this->introspections[0]['options']['body']);
        self::assertContains('Authorization: Basic ' . base64_encode('demo-client:s3cret'), $this->introspections[0]['options']['headers']);
    }

    public function testRevokedTokenIsRefusedAndTheAnswerCached(): void
    {
        $introspector = $this->introspector([new JsonMockResponse(['active' => false])]);
        [$token, $claims] = $this->partnerToken('jti-2');

        foreach ([1, 2] as $attempt) {
            try {
                $introspector->assertActive($token, $claims);
                self::fail('Expected InvalidTokenException');
            } catch (InvalidTokenException) {
            }
        }
        self::assertCount(1, $this->introspections);
    }

    public function testAnswerForAnotherTokenIsNotActive(): void
    {
        $introspector = $this->introspector([new JsonMockResponse(['active' => true, 'jti' => 'someone-else'])]);
        [$token, $claims] = $this->partnerToken('jti-3');

        $this->expectException(InvalidTokenException::class);
        $introspector->assertActive($token, $claims);
    }

    public function testForgetAsksSlsAgain(): void
    {
        $introspector = $this->introspector([new JsonMockResponse(['active' => true]), new JsonMockResponse(['active' => false])]);
        [$token, $claims] = $this->partnerToken('jti-4');

        $introspector->assertActive($token, $claims);
        $introspector->forget();

        $this->expectException(InvalidTokenException::class);
        $introspector->assertActive($token, $claims);
    }

    public function testCachedNoLongerThanTheTokenLives(): void
    {
        $introspector = $this->introspector([new JsonMockResponse(['active' => true]), new JsonMockResponse(['active' => true])]);
        [$token, $claims] = $this->partnerToken('jti-5', ['exp' => time() - 1]);

        $introspector->assertActive($token, $claims);
        $introspector->assertActive($token, $claims);

        self::assertCount(2, $this->introspections, 'an answer for an expired token is not kept');
    }

    public function testUnreachableSlsIsUnavailableAndNotCached(): void
    {
        $introspector = $this->introspector([new MockResponse('', ['error' => 'connection refused']), new JsonMockResponse(['error' => 'server_error'], ['http_code' => 500]), new JsonMockResponse(['active' => true])]);
        [$token, $claims] = $this->partnerToken('jti-6');

        foreach ([1, 2] as $attempt) {
            try {
                $introspector->assertActive($token, $claims);
                self::fail('Expected SlsUnavailableException');
            } catch (SlsUnavailableException) {
            }
        }
        $introspector->assertActive($token, $claims);
        self::assertCount(3, $this->introspections, 'failures are not cached');
    }

    public function testRefusedClientIsUnavailable(): void
    {
        $introspector = $this->introspector([new JsonMockResponse(['error' => 'invalid_client'], ['http_code' => 401])]);
        [$token, $claims] = $this->partnerToken('jti-7');

        $this->expectException(SlsUnavailableException::class);
        $this->expectExceptionMessage('invalid_client');
        $introspector->assertActive($token, $claims);
    }

    public function testLinkTokensAndDisabledIntrospectionAreNotChecked(): void
    {
        $introspector = $this->introspector([]);
        $link         = SlsTestTokens::shared()->linkToken(self::ISS, 'http://app.test', [], 'tenant-1');
        self::assertFalse($introspector->applies(self::claimsOf($link)));
        $introspector->assertActive($link, self::claimsOf($link));

        [$token, $claims] = $this->partnerToken('jti-8');
        $disabled = new PartnerTokenIntrospector($this->client(), $this->cache, false);
        self::assertFalse($disabled->applies($claims));
        $disabled->assertActive($token, $claims);

        self::assertSame([], $this->introspections);
    }

    public function testRememberedAnswersSkipSls(): void
    {
        $introspector = $this->introspector([]);
        [$ok, $okClaims]   = $this->partnerToken('jti-9');
        [$off, $offClaims] = $this->partnerToken('jti-10');
        $introspector->remember($ok, true);
        $introspector->remember($off, false);

        $introspector->assertActive($ok, $okClaims);
        try {
            $introspector->assertActive($off, $offClaims);
            self::fail('Expected InvalidTokenException');
        } catch (InvalidTokenException) {
        }
        self::assertSame([], $this->introspections);
    }

    /** @return array<string, mixed> the payload, unverified */
    public static function claimsOf(string $jwt): array
    {
        return json_decode((string) base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function partnerToken(string $jti, array $claims = []): array
    {
        $token = SlsTestTokens::shared()->partnerToken(self::ISS, 'http://app.test', ['qc:lab.read'], 'tenant-1', 'qc:laboratory', claims: $claims + ['jti' => $jti]);

        return [$token, self::claimsOf($token)];
    }

    /** @param list<MockResponse> $answers */
    private function introspector(array $answers): PartnerTokenIntrospector
    {
        $this->answers = $answers;

        return new PartnerTokenIntrospector($this->client(), $this->cache);
    }

    private function client(): SlsClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with($url, '/.well-known/openid-configuration')) {
                return new JsonMockResponse(['issuer' => self::ISS, 'token_endpoint' => self::ISS . '/oauth2/token', 'introspection_endpoint' => self::ISS . '/oauth2/introspect']);
            }
            $this->introspections[] = ['url' => $url, 'options' => $options];

            return array_shift($this->answers) ?? throw new \LogicException('Unexpected request ' . $method . ' ' . $url);
        });
        $cache = new ArrayAdapter();

        return new SlsClient($http, $cache, new SlsMetadata($http, $cache, self::ISS), 'demo-client', 's3cret');
    }
}
