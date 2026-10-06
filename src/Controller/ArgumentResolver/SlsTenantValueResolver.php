<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Controller\ArgumentResolver;

use Smartlabsys\SlsConnectorBundle\Attribute\SlsSibling;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Passes the tenant {@see \Smartlabsys\SlsConnectorBundle\EventListener\SlsSiblingListener}
 * resolved to a controller argument typed as its class (or a parent / interface of it). Runs before
 * Doctrine's entity resolver, so a `Company $tenant` argument isn't looked up by route parameters.
 */
final class SlsTenantValueResolver implements ValueResolverInterface
{
    /** @return iterable<object> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $tenant = $request->attributes->get(SlsSibling::TENANT_ATTRIBUTE);
        $type   = $argument->getType();
        if (!is_object($tenant) || $type === null || !$tenant instanceof $type) {
            return [];
        }

        return [$tenant];
    }
}
