<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Client;

use Smartlabsys\SlsConnectorBundle\Security\PartnerTokenIntrospector;
use Smartlabsys\SlsConnectorBundle\Webhook\SlsWebhookEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * `connection.*`, `link.*` and `partnership.*` webhooks mean the org's sibling list (or a link or
 * partner) changed: drop
 * the cached discovery answers so the next {@see SlsClient::connections()} and
 * {@see SlsClient::links()} ask SLS again, and (0.3.3) the cached service tokens for siblings of
 * that tenant / org ({@see SlsClient::forgetSiblingTokens()}). `partnership.*` also drops the
 * cached introspection answers for partner tokens ({@see PartnerTokenIntrospector::forget()}).
 */
#[AsEventListener(SlsWebhookEvent::NAME)]
final class DiscoveryCacheListener
{
    public function __construct(
        private SlsClient $client,
        private ?PartnerTokenIntrospector $introspector = null,
    ) {}

    public function __invoke(SlsWebhookEvent $event): void
    {
        if (!str_starts_with($event->type, 'connection.') && !str_starts_with($event->type, 'link.') && !str_starts_with($event->type, 'partnership.')) {
            return;
        }
        if ($event->organizationId !== null) {
            $this->client->forgetConnections($event->organizationId);
        }
        if ($event->tenantId !== null) {
            $this->client->forgetLinks($event->tenantId);
        }
        $this->client->forgetSiblingTokens($event->tenantId, $event->organizationId);
        if (str_starts_with($event->type, 'partnership.')) {
            $this->introspector?->forget();
        }
    }
}
