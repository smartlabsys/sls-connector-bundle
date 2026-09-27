<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\EventListener;

use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\SlsMetadata;
use Smartlabsys\SlsConnectorBundle\Security\OidcLoginFlow;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * RP-initiated logout (doc 03): when a user who signed in with Smartlab logs out of the app, send
 * them on to SLS's end-session endpoint so the SLS session ends too, and back to the app's home.
 * Priority 16: after Symfony's DefaultLogoutListener (64) set its redirect, before the session is
 * invalidated (0). Off with `sls_connector.oidc.rp_logout: false`.
 */
#[AsEventListener(event: LogoutEvent::class, priority: 16)]
final class RpLogoutListener
{
    public function __construct(
        private SlsMetadata $metadata,
        private string $clientId,
        private string $audience,
        private bool $enabled,
    ) {}

    public function __invoke(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->enabled || !$request->hasSession()) {
            return;
        }
        $idToken = $request->getSession()->get(OidcLoginFlow::SESSION_ID_TOKEN);
        if (!is_string($idToken)) {
            return;
        }

        try {
            $endSession = $this->metadata->endpoint('end_session_endpoint');
        } catch (SlsUnavailableException) {
            return;
        }
        $event->setResponse(new RedirectResponse($endSession . '?' . http_build_query([
            'id_token_hint'            => $idToken,
            'client_id'                => $this->clientId,
            'post_logout_redirect_uri' => $this->audience . '/',
        ], '', '&', PHP_QUERY_RFC3986)));
    }
}
