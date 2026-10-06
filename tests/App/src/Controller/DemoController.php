<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Controller;

use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Client\SlsTokenException;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Security\OidcLoginFlow;
use Smartlabsys\SlsConnectorBundle\Security\SlsAppUser;
use Smartlabsys\SlsConnectorBundle\Security\SlsUserTokenHandler;
use Smartlabsys\SlsConnectorBundle\Tests\App\Security\DemoUser;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Twig\Environment;

final class DemoController
{
    public function __construct(
        private Environment $twig,
        private Security $security,
        private JsonStore $store,
        private SlsClient $client,
        private string $appKey = '',
    ) {}

    #[Route('/', name: 'demo_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        return new Response($this->twig->render('home.html.twig', [
            'user'     => $this->security->getUser(),
            'sls'      => [
                'sub' => $request->getSession()->get('_sls.sub'),
                'sid' => $request->getSession()->get('_sls.sid'),
            ],
            'webhooks' => array_slice(array_reverse($this->store->read()['webhooks'] ?? []), 0, 10),
        ]));
    }

    #[Route('/login', name: 'demo_login', methods: ['GET'])]
    public function login(AuthenticationUtils $utils): Response
    {
        return new Response($this->twig->render('login.html.twig', ['error' => $utils->getLastAuthenticationError()]));
    }

    #[Route('/account', name: 'demo_account', methods: ['GET'])]
    public function account(): Response
    {
        return new Response($this->twig->render('home.html.twig', ['user' => $this->security->getUser(), 'sls' => [], 'webhooks' => []]));
    }

    /**
     * Sibling apps of the signed-in user's org (discovery), and a call to one's `/api/ping` as this
     * app (`?call={app}&as=app`) or on the user's behalf (`&as=user`, token exchange) — doc 09.
     */
    #[Route('/siblings', name: 'demo_siblings', methods: ['GET'])]
    public function siblings(Request $request): Response
    {
        /** @var DemoUser $user */
        $user           = $this->security->getUser();
        $organizationId = $this->organizationOf($user);
        $connections    = [];
        $result         = null;
        $error          = null;
        try {
            $connections = $organizationId !== null ? $this->client->connections($organizationId, $request->query->has('refresh')) : [];
            $app         = $request->query->getString('call');
            if ($organizationId !== null && $app !== '') {
                $onBehalf    = $request->query->getString('as') === 'user';
                $accessToken = $onBehalf ? OidcLoginFlow::accessToken($request->getSession()) : null;
                if ($onBehalf && $accessToken === null) {
                    $error = 'demo.siblings.error.session_expired';
                } else {
                    $response = $this->client->callSibling($organizationId, $app, 'GET', '/ping', [], $accessToken);
                    $result   = ['app' => $app, 'as' => $onBehalf ? 'user' : 'app', 'status' => $response->getStatusCode(), 'body' => $response->toArray(false)];
                }
            }
        } catch (SlsTokenException $e) {
            $result = ['app' => $request->query->getString('call'), 'as' => $request->query->getString('as'), 'status' => null, 'body' => ['error' => $e->error, 'error_description' => $e->description]];
        } catch (SlsUnavailableException|ExceptionInterface $e) {
            $error = $e->getMessage();
        }

        return new Response($this->twig->render('siblings.html.twig', [
            'user'           => $user,
            'organizationId' => $organizationId,
            'self'           => $this->appKey,
            'connections'    => $connections,
            'result'         => $result,
            'error'          => $error,
        ]));
    }

    /** Who is calling: a sibling app as itself, or a user (directly or through token exchange). */
    #[Route('/api/ping', name: 'demo_api_ping', methods: ['GET'])]
    public function ping(Request $request): JsonResponse
    {
        $caller = $this->security->getUser();
        $claims = $request->attributes->get(SlsUserTokenHandler::CLAIMS_ATTRIBUTE, []);
        if ($caller instanceof SlsAppUser) {
            return new JsonResponse(['app' => $this->appKey, 'caller' => 'app', 'client_id' => $caller->clientId, 'from' => $caller->app, 'tenant_id' => $caller->tenantId, 'scopes' => $caller->scopes]);
        }

        return new JsonResponse([
            'app'       => $this->appKey,
            'caller'    => 'user',
            'email'     => $caller instanceof DemoUser ? $caller->email : null,
            'roles'     => $claims['roles'] ?? [],
            'tenant_id' => $claims['tenant_id'] ?? null,
            'act'       => $claims['act'] ?? null,
            'scope'     => $claims['scope'] ?? null,
        ]);
    }

    /** An endpoint behind an app-link scope (doc 09): 403 unless the token carries `{app}:orders.read`. */
    #[Route('/api/orders', name: 'demo_api_orders', methods: ['GET'])]
    public function orders(): JsonResponse
    {
        if (!$this->security->isGranted('SLS_SCOPE:' . $this->appKey . ':orders.read')) {
            return new JsonResponse(['error' => 'insufficient_scope'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['app' => $this->appKey, 'orders' => []]);
    }

    #[Route('/api/me', name: 'demo_api_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof DemoUser) {
            return new JsonResponse(['error' => 'not_a_user'], Response::HTTP_FORBIDDEN);
        }
        $claims = $request->attributes->get(SlsUserTokenHandler::CLAIMS_ATTRIBUTE, []);

        return new JsonResponse(['id' => $user->id, 'email' => $user->email, 'sls_sub' => $user->slsSub, 'scope' => $claims['scope'] ?? null]);
    }

    private function organizationOf(DemoUser $user): ?string
    {
        return $this->store->read()['users'][$user->id]['org_id'] ?? null;
    }
}
