<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Controller;

use Smartlabsys\SlsConnectorBundle\Security\SlsUserTokenHandler;
use Smartlabsys\SlsConnectorBundle\Tests\App\Security\DemoUser;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

final class DemoController
{
    public function __construct(private Environment $twig, private Security $security, private JsonStore $store) {}

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

    #[Route('/api/me', name: 'demo_api_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        /** @var DemoUser $user */
        $user   = $this->security->getUser();
        $claims = $request->attributes->get(SlsUserTokenHandler::CLAIMS_ATTRIBUTE, []);

        return new JsonResponse(['id' => $user->id, 'email' => $user->email, 'sls_sub' => $user->slsSub, 'scope' => $claims['scope'] ?? null]);
    }
}
