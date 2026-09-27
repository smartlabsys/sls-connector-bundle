<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Client;

use Smartlabsys\SlsConnectorBundle\Webhook\SlsWebhookEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * `connection.*` webhooks mean the org's sibling list changed: drop the cached discovery answer
 * so the next {@see SlsClient::connections()} asks SLS again.
 */
#[AsEventListener(SlsWebhookEvent::NAME)]
final class DiscoveryCacheListener
{
    public function __construct(private SlsClient $client) {}

    public function __invoke(SlsWebhookEvent $event): void
    {
        if ($event->organizationId !== null && str_starts_with($event->type, 'connection.')) {
            $this->client->forgetConnections($event->organizationId);
        }
    }
}
