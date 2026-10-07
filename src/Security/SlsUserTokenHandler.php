<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Psr\Log\LoggerInterface;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\InvalidTokenException;
use Smartlabsys\SlsConnectorBundle\Jwt\TokenValidator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * `access_token` handler for the app's own `/api` and `/mcp` (doc 05 §6): SLS-issued user access
 * tokens whose `aud` is this instance, mapped to the local user by
 * {@see SlsUserResolverInterface::loadBySlsUserId()}. The validated claims are kept on the request
 * as `_sls_claims` (org, roles, scope). Service tokens (`sub` = `sls`) are refused here.
 *
 * Tokens a sibling app got for itself (`sub` = its `client_id`, doc 09) become an {@see SlsAppUser}
 * when `sls_connector.api.accept_app_tokens` is on, and are refused otherwise.
 *
 * A token with a `partnership_id` (a partner's app or user token) is also checked with SLS's
 * introspection, {@see PartnerTokenIntrospector} (0.3.3): refused once SLS revoked it (the
 * partnership ended), and refused while SLS can't be asked (fail closed).
 */
final class SlsUserTokenHandler implements AccessTokenHandlerInterface
{
    public const CLAIMS_ATTRIBUTE = '_sls_claims';

    public function __construct(
        private TokenValidator $validator,
        private RequestStack $requestStack,
        private ?SlsUserResolverInterface $resolver = null,
        private ?LoggerInterface $logger = null,
        private bool $acceptAppTokens = false,
        private ?PartnerTokenIntrospector $introspector = null,
    ) {}

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            $claims = $this->validator->validateAccessToken($accessToken);
        } catch (InvalidTokenException|SlsUnavailableException $e) {
            $this->logger?->info('SLS access token rejected: {reason}', ['reason' => $e->getMessage()]);

            throw new BadCredentialsException('Invalid SLS access token.', 0, $e);
        }
        if ($claims['sub'] === SlsServiceTokenHandler::SUBJECT) {
            throw new BadCredentialsException('SLS service tokens are not accepted here.');
        }
        if ($this->introspector?->applies($claims)) {
            if (isset($claims['client_id']) && $claims['sub'] === $claims['client_id'] && !$this->acceptAppTokens) {
                throw new BadCredentialsException('App tokens are not accepted here.');
            }
            try {
                $this->introspector->assertActive($accessToken, $claims);
            } catch (InvalidTokenException $e) {
                $this->logger?->info('SLS partner token rejected: {reason}', ['reason' => $e->getMessage(), 'partnership_id' => $claims['partnership_id']]);

                throw new BadCredentialsException('This partner token is no longer valid.', 0, $e);
            } catch (SlsUnavailableException $e) {
                $this->logger?->warning('SLS partner token refused, SLS introspection unavailable: {reason}', ['reason' => $e->getMessage(), 'partnership_id' => $claims['partnership_id']]);

                throw new BadCredentialsException('The partner token could not be checked with SLS.', 0, $e);
            }
        }
        if (isset($claims['client_id']) && $claims['sub'] === $claims['client_id']) {
            if (!$this->acceptAppTokens) {
                throw new BadCredentialsException('App tokens are not accepted here.');
            }
            $this->requestStack->getCurrentRequest()?->attributes->set(self::CLAIMS_ATTRIBUTE, $claims);
            $app = new SlsAppUser($claims);

            return new UserBadge($app->getUserIdentifier(), static fn () => $app);
        }
        if ($this->resolver === null) {
            throw new \LogicException('Implement ' . SlsUserResolverInterface::class . ' to accept SLS user tokens.');
        }

        $user = $this->resolver->loadBySlsUserId($claims['sub'], $claims);
        if ($user === null) {
            throw new BadCredentialsException('No local user is linked to this SLS user.');
        }
        $this->requestStack->getCurrentRequest()?->attributes->set(self::CLAIMS_ATTRIBUTE, $claims);

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
