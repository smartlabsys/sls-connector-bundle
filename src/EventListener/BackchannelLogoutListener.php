<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\EventListener;

use Smartlabsys\SlsConnectorBundle\Security\LogoutRegistry;
use Smartlabsys\SlsConnectorBundle\Security\OidcLoginFlow;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ends local sessions that SLS logged out through the back-channel: runs before the firewall
 * (priority 16 > 8), so the firewall then finds no token and the user is anonymous.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class BackchannelLogoutListener
{
    public function __construct(private LogoutRegistry $registry) {}

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }
        $session = $request->getSession();
        $authAt  = $session->get(OidcLoginFlow::SESSION_AUTH_AT);
        if (!is_int($authAt)) {
            return;
        }

        if ($this->registry->isRevoked($session->get(OidcLoginFlow::SESSION_SUBJECT), $session->get(OidcLoginFlow::SESSION_SID), $authAt)) {
            $session->invalidate();
        }
    }
}
