<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Smartlabsys\SlsConnectorBundle\Webhook\SlsWebhookEvent;
use Smartlabsys\SlsConnectorBundle\Webhook\WebhookSignature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Webhook receiver `POST /sls/webhooks` (doc 05 §5, doc 09): verifies the HMAC signature and
 * timestamp, drops redeliveries of an `event_id` it has already processed (7 days), then
 * dispatches {@see SlsWebhookEvent}. The event is only marked processed once every listener
 * succeeded, so a failing listener gets the delivery retried.
 */
final class WebhookController
{
    use ContractResponses;

    private const PROCESSED_TTL = 604800;

    public function __construct(
        private EventDispatcherInterface $dispatcher,
        #[Autowire(service: 'sls_connector.cache')] private CacheItemPoolInterface $cache,
        #[Autowire('%sls_connector.webhook_secret%')] #[\SensitiveParameter] private string $webhookSecret,
        private ?LoggerInterface $logger = null,
    ) {}

    #[Route('/sls/webhooks', name: 'sls_connector.webhooks', methods: ['POST'])]
    public function receive(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request): JsonResponse {
            $body = $request->getContent();
            if (!WebhookSignature::verify(
                $this->webhookSecret,
                $request->headers->get(WebhookSignature::TIMESTAMP_HEADER),
                $request->headers->get(WebhookSignature::SIGNATURE_HEADER),
                $body,
            )) {
                throw new ContractException(401, 'invalid_signature', 'Missing, invalid or stale webhook signature.');
            }

            $payload = $this->jsonBody($request);
            $eventId = $payload['event_id'] ?? null;
            $type    = $payload['type'] ?? null;
            if (!is_string($eventId) || $eventId === '' || !is_string($type) || !preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $type)) {
                throw ContractException::badRequest('"event_id" and a dotted "type" are required.');
            }
            if (isset($payload['data']) && !is_array($payload['data'])) {
                throw ContractException::badRequest('"data" must be an object.');
            }

            $processed = $this->cache->getItem('sls_connector.webhook.' . sha1($eventId));
            if ($processed->isHit()) {
                return new JsonResponse(['status' => 'duplicate']);
            }

            $event = new SlsWebhookEvent(
                $eventId,
                $type,
                self::optionalString($payload['org_id'] ?? null),
                self::optionalString($payload['tenant_id'] ?? null),
                self::date($payload['occurred_at'] ?? null),
                $payload['data'] ?? [],
                $payload,
            );
            $this->dispatcher->dispatch($event, SlsWebhookEvent::NAME);
            $this->dispatcher->dispatch($event, $event->eventName());

            $this->cache->save($processed->set(true)->expiresAfter(self::PROCESSED_TTL));
            $this->logger?->info('SLS webhook {type} ({event_id}) processed.', ['type' => $type, 'event_id' => $eventId]);

            return new JsonResponse(['status' => 'received']);
        });
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
