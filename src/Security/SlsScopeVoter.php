<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * `#[IsGranted('SLS_SCOPE:demo:orders.read')]` (doc 09, app links): the caller's SLS access token
 * carries that scope. Sibling apps calling as themselves ({@see SlsAppUser}) and users whose token a
 * sibling exchanged for this app (claims on the request) both count; a session login never does,
 * so guard sibling-facing endpoints with it, not the app's own pages.
 *
 * @extends Voter<string, mixed>
 */
final class SlsScopeVoter extends Voter
{
    public const PREFIX = 'SLS_SCOPE:';

    public function __construct(private RequestStack $requestStack) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, self::PREFIX);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $scope = substr($attribute, strlen(self::PREFIX));
        $user  = $token->getUser();
        if ($user instanceof SlsAppUser) {
            return $user->hasScope($scope);
        }
        $claims = $this->requestStack->getCurrentRequest()?->attributes->get(SlsUserTokenHandler::CLAIMS_ATTRIBUTE);

        return is_array($claims) && in_array($scope, explode(' ', (string) ($claims['scope'] ?? '')), true);
    }
}
