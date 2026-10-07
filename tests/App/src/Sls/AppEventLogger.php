<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Event\SlsAppEvent;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Keeps the last events other apps sent through SLS (types under `events.consumes`). */
#[AsEventListener(SlsAppEvent::NAME)]
final class AppEventLogger
{
    public function __construct(private JsonStore $store) {}

    public function __invoke(SlsAppEvent $event): void
    {
        $this->store->update(static function (array &$data) use ($event): void {
            $data['app_events'][] = [
                'event_id'  => $event->eventId,
                'type'      => $event->type,
                'tenant_id' => $event->tenantId,
                'data'      => $event->data,
                'source'    => $event->source,
                'envelope'  => $event->envelope,
            ];
            $data['app_events'] = array_slice($data['app_events'], -50);
        });
    }
}
