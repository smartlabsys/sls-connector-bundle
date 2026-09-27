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
 */
final class SlsUserTokenHandler implements AccessTokenHandlerInterface
{
    public const CLAIMS_ATTRIBUTE = '_sls_claims';

    public function __construct(
        private TokenValidator $validator,
        private RequestStack $requestStack,
        private ?SlsUserResolverInterface $resolver = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        if ($this->resolver === null) {
            throw new \LogicException('Implement ' . SlsUserResolverInterface::class . ' to accept SLS user tokens.');
        }
        try {
            $claims = $this->validator->validateAccessToken($accessToken);
        } catch (InvalidTokenException|SlsUnavailableException $e) {
            $this->logger?->info('SLS access token rejected: {reason}', ['reason' => $e->getMessage()]);

            throw new BadCredentialsException('Invalid SLS access token.', 0, $e);
        }
        if ($claims['sub'] === SlsServiceTokenHandler::SUBJECT) {
            throw new BadCredentialsException('SLS service tokens are not accepted here.');
        }

        $user = $this->resolver->loadBySlsUserId($claims['sub'], $claims);
        if ($user === null) {
            throw new BadCredentialsException('No local user is linked to this SLS user.');
        }
        $this->requestStack->getCurrentRequest()?->attributes->set(self::CLAIMS_ATTRIBUTE, $claims);

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
