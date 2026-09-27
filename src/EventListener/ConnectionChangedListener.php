<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\EventListener;

use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Webhook\SlsWebhookEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Drops the cached discovery answer of an org when one of its connections changes (doc 09). */
#[AsEventListener(SlsWebhookEvent::NAME)]
final class ConnectionChangedListener
{
    public function __construct(private SlsClient $client) {}

    public function __invoke(SlsWebhookEvent $event): void
    {
        if (str_starts_with($event->type, 'connection.') && $event->organizationId !== null) {
            $this->client->forgetConnections($event->organizationId);
        }
    }
}
