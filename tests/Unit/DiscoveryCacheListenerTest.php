<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Client\DiscoveryCacheListener;
use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Jwt\InvalidTokenException;
use Smartlabsys\SlsConnectorBundle\Security\PartnerTokenIntrospector;
use Smartlabsys\SlsConnectorBundle\Test\SlsTestTokens;
use Smartlabsys\SlsConnectorBundle\Webhook\SlsWebhookEvent;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DiscoveryCacheListenerTest extends TestCase
{
    public function testLinkEventsDropBothCaches(): void
    {
        $client = $this->createMock(SlsClient::class);
        $client->expects(self::once())->method('forgetConnections')->with('org-1');
        $client->expects(self::once())->method('forgetLinks')->with('tenant-a');

        (new DiscoveryCacheListener($client))(self::event('link.created'));
    }

    public function testConnectionEventsDropBothCaches(): void
    {
        $client = $this->createMock(SlsClient::class);
        $client->expects(self::once())->method('forgetConnections')->with('org-1');
        $client->expects(self::once())->method('forgetLinks')->with('tenant-a');

        (new DiscoveryCacheListener($client))(self::event('connection.disconnected'));
    }

    public function testPartnershipEventsDropBothCaches(): void
    {
        $client = $this->createMock(SlsClient::class);
        $client->expects(self::once())->method('forgetConnections')->with('org-1');
        $client->expects(self::once())->method('forgetLinks')->with('tenant-a');

        (new DiscoveryCacheListener($client))(self::event('partnership.activated'));
    }

    public function testLinkConnectionAndPartnershipEventsDropSiblingTokens(): void
    {
        foreach (['link.deleted', 'connection.disconnected', 'partnership.ended', 'partnership.declined'] as $type) {
            $client = $this->createMock(SlsClient::class);
            $client->expects(self::once())->method('forgetSiblingTokens')->with('tenant-a', 'org-1');

            (new DiscoveryCacheListener($client))(self::event($type));
        }
    }

    public function testOnlyPartnershipEventsDropIntrospectionAnswers(): void
    {
        $sls = $this->createMock(SlsClient::class);
        $sls->expects(self::once())->method('introspect')->willReturn(['active' => false]);
        $introspector = new PartnerTokenIntrospector($sls, new ArrayAdapter());
        $token        = SlsTestTokens::shared()->partnerToken('https://sls.test', 'http://app.test', [], 't', 'r');
        $claims       = PartnerTokenIntrospectorTest::claimsOf($token);
        $introspector->remember($token, true);

        (new DiscoveryCacheListener($this->createMock(SlsClient::class), $introspector))(self::event('link.deleted'));
        $introspector->assertActive($token, $claims); // still remembered, SLS not asked

        (new DiscoveryCacheListener($this->createMock(SlsClient::class), $introspector))(self::event('partnership.ended'));
        $this->expectException(InvalidTokenException::class);
        $introspector->assertActive($token, $claims); // asked again: revoked
    }

    public function testOtherEventsKeepTheCaches(): void
    {
        $client = $this->createMock(SlsClient::class);
        $client->expects(self::never())->method('forgetConnections');
        $client->expects(self::never())->method('forgetLinks');
        $client->expects(self::never())->method('forgetSiblingTokens');

        (new DiscoveryCacheListener($client))(self::event('user.assigned'));
    }

    private static function event(string $type): SlsWebhookEvent
    {
        return new SlsWebhookEvent('evt-1', $type, 'org-1', 'tenant-a', null, [], []);
    }
}
