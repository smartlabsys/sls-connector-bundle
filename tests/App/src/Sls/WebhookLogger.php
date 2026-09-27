<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;
use Smartlabsys\SlsConnectorBundle\Webhook\SlsWebhookEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Keeps the last webhooks so the demo home page can show them. */
#[AsEventListener(SlsWebhookEvent::NAME)]
final class WebhookLogger
{
    public function __construct(private JsonStore $store) {}

    public function __invoke(SlsWebhookEvent $event): void
    {
        $this->store->update(static function (array &$data) use ($event): void {
            $data['webhooks'][] = ['event_id' => $event->eventId, 'type' => $event->type, 'org_id' => $event->organizationId];
            $data['webhooks']   = array_slice($data['webhooks'], -50);
        });
    }
}
