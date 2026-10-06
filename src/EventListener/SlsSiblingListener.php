<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\EventListener;

use Smartlabsys\SlsConnectorBundle\Attribute\SlsSibling;
use Smartlabsys\SlsConnectorBundle\Security\SlsAppUser;
use Smartlabsys\SlsConnectorBundle\Security\SlsTenantResolverInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforces {@see SlsSibling} for sibling app callers: the scope (403 `forbidden`), then the tenant
 * through the app's resolver (404 `tenant_not_found`) into the `_sls_tenant` request attribute,
 * which {@see \Smartlabsys\SlsConnectorBundle\Controller\ArgumentResolver\SlsTenantValueResolver}
 * hands to an argument typed as the tenant class.
 */
#[AsEventListener(KernelEvents::CONTROLLER)]
final class SlsSiblingListener
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ?SlsTenantResolverInterface $tenantResolver = null,
    ) {}

    public function __invoke(ControllerEvent $event): void
    {
        $attributes = $event->getAttributes(SlsSibling::class);
        if ($attributes === []) {
            return;
        }
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof SlsAppUser) {
            return;
        }

        foreach ($attributes as $attribute) {
            if ($attribute->scope !== null && !$user->hasScope($attribute->scope)) {
                $event->setController(static fn (): JsonResponse => new JsonResponse(['error' => 'forbidden'], 403));

                return;
            }
        }

        if ($this->tenantResolver === null || $user->tenantId === null) {
            return;
        }
        $tenant = $this->tenantResolver->resolveTenant($user->tenantId);
        if ($tenant === null) {
            $event->setController(static fn (): JsonResponse => new JsonResponse(['error' => 'tenant_not_found'], 404));

            return;
        }
        $event->getRequest()->attributes->set(SlsSibling::TENANT_ATTRIBUTE, $tenant);
    }
}
