<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Webhook;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * A verified SLS webhook (doc 09). Dispatched twice: as `sls.webhook` (every event) and as
 * `sls.webhook.<type>` (e.g. `sls.webhook.user.assigned`), so listeners pick what they need:
 *
 *     #[AsEventListener('sls.webhook.connection.created')]
 *     public function onConnection(SlsWebhookEvent $event): void { … }
 *
 * A listener that throws makes the receiver answer 500, so SLS retries the delivery.
 */
final class SlsWebhookEvent extends Event
{
    public const NAME = 'sls.webhook';

    /**
     * @param array<string, mixed> $data    the event's `data`
     * @param array<string, mixed> $payload the whole envelope
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly ?string $organizationId,
        public readonly ?string $tenantId,
        public readonly ?\DateTimeImmutable $occurredAt,
        public readonly array $data,
        public readonly array $payload,
    ) {}

    public function eventName(): string
    {
        return self::NAME . '.' . $this->type;
    }
}
