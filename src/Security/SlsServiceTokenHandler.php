<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Psr\Log\LoggerInterface;
use Smartlabsys\SlsConnectorBundle\Exception\SlsUnavailableException;
use Smartlabsys\SlsConnectorBundle\Jwt\InvalidTokenException;
use Smartlabsys\SlsConnectorBundle\Jwt\TokenValidator;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * `access_token` handler for the firewall in front of `/sls/health`, `/sls/provisioning` and
 * `/scim/v2`: accepts only SLS service tokens (`sub` = `client_id` = `sls`, `aud` = this instance)
 * and yields an {@see SlsServiceUser}.
 */
final class SlsServiceTokenHandler implements AccessTokenHandlerInterface
{
    public const SUBJECT = 'sls';

    public function __construct(
        private TokenValidator $validator,
        private ?LoggerInterface $logger = null,
    ) {}

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $user = $this->userFrom($accessToken);

        return new UserBadge($user->getUserIdentifier(), static fn (): SlsServiceUser => $user);
    }

    /** @throws BadCredentialsException */
    public function userFrom(#[\SensitiveParameter] string $accessToken): SlsServiceUser
    {
        try {
            $claims = $this->validator->validateAccessToken($accessToken);
        } catch (InvalidTokenException|SlsUnavailableException $e) {
            $this->logger?->info('SLS service token rejected: {reason}', ['reason' => $e->getMessage()]);

            throw new BadCredentialsException('Invalid SLS service token.', 0, $e);
        }
        if ($claims['sub'] !== self::SUBJECT || ($claims['client_id'] ?? null) !== self::SUBJECT) {
            throw new BadCredentialsException('Not an SLS service token.');
        }

        return new SlsServiceUser($claims);
    }
}
