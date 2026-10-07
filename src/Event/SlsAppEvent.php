<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * An event another app sent, brokered by SLS (doc 09 "Events between apps", 0.3): a webhook whose
 * envelope carries `source`, of a type listed under `sls_connector.events.consumes`. Dispatched
 * as `sls.event` (every one) and `sls.event.<type>` (e.g. `sls.event.qc.request.created`), after
 * the raw `sls.webhook` / `sls.webhook.<type>`:
 *
 *     #[AsEventListener('sls.event.qc.request.created')]
 *     public function onRequest(SlsAppEvent $event): void
 *     {
 *         $tenant = $event->tenantId;                       // this app's tenant
 *         $qc     = $event->source['connection_id'];        // to call the sender back
 *     }
 *
 * A listener that throws makes the receiver answer 500, so SLS retries the delivery.
 */
final class SlsAppEvent extends Event
{
    public const NAME = 'sls.event';

    /**
     * @param array<string, mixed>   $data   the event's `data`, as the sender sent it
     * @param array<string, ?string> $source   the sender: `connection_id`, `app`, `instance_id`,
     *                                         `company_id`, `company_name`, `tenant_id`
     * @param array<string, mixed>   $envelope the whole webhook body as SLS delivered it (0.3.1), for
     *                                         a listener that hands the event on as is
     */
    public function __construct(
        public readonly string $type,
        public readonly string $eventId,
        public readonly ?string $tenantId,
        public readonly ?string $organizationId,
        public readonly ?\DateTimeImmutable $occurredAt,
        public readonly array $data,
        public readonly array $source,
        public readonly array $envelope = [],
    ) {}

    /** The sending app's key (`qc`). */
    public function sourceApp(): ?string
    {
        return $this->source['app'] ?? null;
    }

    public function eventName(): string
    {
        return self::NAME . '.' . $this->type;
    }
}
