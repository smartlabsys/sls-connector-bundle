<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Security\SlsAppUser;
use Smartlabsys\SlsConnectorBundle\Security\SlsScopeVoter;
use Smartlabsys\SlsConnectorBundle\Security\SlsUserTokenHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SlsScopeVoterTest extends TestCase
{
    public function testAppUserNeedsTheScope(): void
    {
        $voter = new SlsScopeVoter(new RequestStack());
        $token = new UsernamePasswordToken(new SlsAppUser(['sub' => 'client', 'scope' => 'demo:orders.read']), 'api');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, ['SLS_SCOPE:demo:orders.read']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, ['SLS_SCOPE:demo:orders.write']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['ROLE_USER']));
    }

    public function testUserTokenScopesComeFromTheRequestClaims(): void
    {
        $stack = new RequestStack();
        $token = new UsernamePasswordToken(new InMemoryUser('ana', null, ['ROLE_USER']), 'api');
        $voter = new SlsScopeVoter($stack);

        $stack->push(new Request());
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, ['SLS_SCOPE:demo:orders.read']), 'session login');

        $request = new Request();
        $request->attributes->set(SlsUserTokenHandler::CLAIMS_ATTRIBUTE, ['scope' => 'openid demo:orders.read']);
        $stack->push($request);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, ['SLS_SCOPE:demo:orders.read']));
    }
}
