<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Psr\Log\LoggerInterface;
use Smartlabsys\SlsConnectorBundle\Client\SlsClient;
use Smartlabsys\SlsConnectorBundle\Client\SlsTokenException;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\InvalidTokenException;
use Smartlabsys\SlsConnectorBundle\Jwt\TokenValidator;
use Smartlabsys\SlsConnectorBundle\Manifest\ManifestBuilder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Handles `/sls/oidc/callback` (doc 05 §2): checks `state`, exchanges the code (PKCE, client
 * secret), validates the ID token (signature, `iss`, `aud`, nonce), and asks the app's
 * {@see SlsUserResolverInterface} for the local user. Add it to the app's main (session) firewall
 * as a `custom_authenticator`.
 *
 * Failures go to the configured failure path with the error in the session, like form login, so
 * `last_authentication_error` shows it (message keys `sls.login.*`).
 */
final class SlsOidcAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    private const IDENTITY_ATTRIBUTE     = 'sls_identity';
    private const TARGET_ATTRIBUTE       = 'sls_target';
    private const ID_TOKEN_ATTRIBUTE     = 'sls_id_token';
    private const ACCESS_TOKEN_ATTRIBUTE = 'sls_access_token';

    /** @param array{default_target_path: string, failure_path: string} $oidcConfig */
    public function __construct(
        private OidcLoginFlow $flow,
        private SlsClient $client,
        private TokenValidator $validator,
        private array $oidcConfig,
        private ?SlsUserResolverInterface $resolver = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->getPathInfo() === ManifestBuilder::ENDPOINTS['oidc_callback'] && $request->isMethod('GET');
    }

    public function authenticate(Request $request): Passport
    {
        if ($this->resolver === null) {
            throw new \LogicException('Implement ' . SlsUserResolverInterface::class . ' to use "Sign in with Smartlab".');
        }
        $session = $request->getSession();
        $pending = $this->flow->consume($session, (string) $request->query->get('state', ''));
        if ($pending === null) {
            throw new CustomUserMessageAuthenticationException('sls.login.expired');
        }
        if ($request->query->has('error')) {
            $this->logger?->info('SLS login returned {error}.', ['error' => $request->query->get('error')]);

            throw new CustomUserMessageAuthenticationException(
                $request->query->get('error') === 'access_denied' ? 'sls.login.denied' : 'sls.login.failed',
            );
        }

        try {
            $tokens = $this->client->tokenRequest([
                'grant_type'    => 'authorization_code',
                'code'          => (string) $request->query->get('code', ''),
                'redirect_uri'  => $this->flow->redirectUri(),
                'code_verifier' => $pending['verifier'],
            ]);
            if (!is_string($tokens['id_token'] ?? null)) {
                throw new InvalidTokenException('The token response has no ID token.');
            }
            $claims = $this->validator->validateIdToken($tokens['id_token'], $pending['nonce']);
            if (!isset($claims['email']) && isset($tokens['access_token'])) {
                $info = $this->client->userInfo($tokens['access_token']);
                if (($info['sub'] ?? null) === $claims['sub']) {
                    $claims += $info;
                }
            }
        } catch (SlsTokenException|InvalidTokenException|SlsUnavailableException $e) {
            $this->logger?->warning('SLS login failed: {reason}', ['reason' => $e->getMessage()]);

            throw new CustomUserMessageAuthenticationException('sls.login.failed');
        }

        $identity = new SlsIdentity($claims);
        $user     = $this->resolver->resolveOidcUser($identity);
        if ($user === null) {
            throw new CustomUserMessageAuthenticationException('sls.login.denied');
        }

        $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
        $passport->setAttribute(self::IDENTITY_ATTRIBUTE, $identity);
        $passport->setAttribute(self::TARGET_ATTRIBUTE, $pending['target']);
        $passport->setAttribute(self::ID_TOKEN_ATTRIBUTE, $tokens['id_token']);
        $passport->setAttribute(self::ACCESS_TOKEN_ATTRIBUTE, is_string($tokens['access_token'] ?? null)
            ? [$tokens['access_token'], time() + (int) ($tokens['expires_in'] ?? 0)]
            : null);

        return $passport;
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $token = parent::createToken($passport, $firewallName);
        $token->setAttribute(self::IDENTITY_ATTRIBUTE, $passport->getAttribute(self::IDENTITY_ATTRIBUTE));
        $token->setAttribute(self::TARGET_ATTRIBUTE, $passport->getAttribute(self::TARGET_ATTRIBUTE));
        $token->setAttribute(self::ID_TOKEN_ATTRIBUTE, $passport->getAttribute(self::ID_TOKEN_ATTRIBUTE));
        $token->setAttribute(self::ACCESS_TOKEN_ATTRIBUTE, $passport->getAttribute(self::ACCESS_TOKEN_ATTRIBUTE));

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var SlsIdentity $identity */
        $identity = $token->getAttribute(self::IDENTITY_ATTRIBUTE);
        $session  = $request->getSession();
        $session->set(OidcLoginFlow::SESSION_SUBJECT, $identity->subject());
        $session->set(OidcLoginFlow::SESSION_SID, $identity->sessionId());
        $session->set(OidcLoginFlow::SESSION_AUTH_AT, time());
        $session->set(OidcLoginFlow::SESSION_ID_TOKEN, $token->getAttribute(self::ID_TOKEN_ATTRIBUTE));
        [$accessToken, $expires] = $token->getAttribute(self::ACCESS_TOKEN_ATTRIBUTE) ?? [null, null];
        $session->set(OidcLoginFlow::SESSION_ACCESS_TOKEN, $accessToken);
        $session->set(OidcLoginFlow::SESSION_ACCESS_TOKEN_EXPIRES, $expires);
        // Keep the token out of the serialized security token.
        $token->setAttribute(self::ACCESS_TOKEN_ATTRIBUTE, null);

        $target = $token->getAttribute(self::TARGET_ATTRIBUTE)
            ?? OidcLoginFlow::safeTarget($this->getTargetPath($session, $firewallName))
            ?? $this->oidcConfig['default_target_path'];
        $this->removeTargetPath($session, $firewallName);

        return new RedirectResponse($target);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->oidcConfig['failure_path']);
    }
}
