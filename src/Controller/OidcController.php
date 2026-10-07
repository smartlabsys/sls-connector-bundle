<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Controller;

use Psr\Log\LoggerInterface;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\InvalidTokenException;
use Smartlabsys\SlsConnectorBundle\Jwt\TokenValidator;
use Smartlabsys\SlsConnectorBundle\Security\LogoutRegistry;
use Smartlabsys\SlsConnectorBundle\Security\OidcLoginFlow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * "Sign in with Smartlab" start + OIDC Back-Channel Logout receiver (doc 05 §2). The callback
 * itself is handled by {@see \Smartlabsys\SlsConnectorBundle\Security\SlsOidcAuthenticator}.
 */
final class OidcController
{
    public function __construct(
        private OidcLoginFlow $flow,
        private TokenValidator $validator,
        private LogoutRegistry $logoutRegistry,
        private ?LoggerInterface $logger = null,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {}

    #[Route('/sls/oidc/login', name: 'sls_connector.oidc_login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        $prompt = $request->query->get('prompt');
        if ($prompt !== 'none') {
            $this->signOutLocally($request);
        }

        return new RedirectResponse($this->flow->authorizationUrl(
            $request->getSession(),
            $request->query->get('target'),
            in_array($prompt, ['none', 'consent', 'login'], true) ? $prompt : null,
        ));
    }

    /**
     * Only reached when the authenticator isn't in the firewall (misconfiguration) — the
     * authenticator answers the callback itself.
     */
    #[Route('/sls/oidc/callback', name: 'sls_connector.oidc_callback', methods: ['GET'])]
    public function callback(): Response
    {
        throw new \LogicException('Add Smartlabsys\SlsConnectorBundle\Security\SlsOidcAuthenticator to your main firewall\'s custom_authenticators.');
    }

    #[Route('/sls/oidc/backchannel-logout', name: 'sls_connector.backchannel_logout', methods: ['POST'])]
    public function backchannelLogout(Request $request): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store'];
        $token   = $request->request->get('logout_token');
        if (!is_string($token) || $token === '') {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'logout_token is required.'], 400, $headers);
        }

        try {
            $claims = $this->validator->validateLogoutToken($token);
        } catch (InvalidTokenException|SlsUnavailableException $e) {
            $this->logger?->info('SLS logout token rejected: {reason}', ['reason' => $e->getMessage()]);

            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'The logout token is invalid.'], 400, $headers);
        }

        if (is_string($claims['sid'] ?? null)) {
            $this->logoutRegistry->revokeSession($claims['sid']);
        } else {
            $this->logoutRegistry->revokeSubject($claims['sub'], (int) $claims['iat']);
        }

        return new JsonResponse(null, 200, $headers);
    }

    /**
     * Starting a sign-in replaces whoever is signed in here — e.g. a launcher tile for another
     * company — so drop the local session first: a cancelled or refused sign-in then leaves nobody
     * signed in rather than the previous user, and nothing of theirs carries over. Not a logout
     * (no LogoutEvent): the SLS session stays, so the sign-in goes straight through.
     */
    private function signOutLocally(Request $request): void
    {
        if ($this->tokenStorage?->getToken() === null || !$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $locale  = $session->get('_locale');
        $this->tokenStorage->setToken(null);
        $session->invalidate();
        if ($locale !== null) {
            $session->set('_locale', $locale);
        }
    }
}
