<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Client\DiscoveryCacheListener;
use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Webhook\SlsWebhookEvent;

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

    public function testOtherEventsKeepTheCaches(): void
    {
        $client = $this->createMock(SlsClient::class);
        $client->expects(self::never())->method('forgetConnections');
        $client->expects(self::never())->method('forgetLinks');

        (new DiscoveryCacheListener($client))(self::event('user.assigned'));
    }

    private static function event(string $type): SlsWebhookEvent
    {
        return new SlsWebhookEvent('evt-1', $type, 'org-1', 'tenant-a', null, [], []);
    }
}
