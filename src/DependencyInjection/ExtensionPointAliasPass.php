<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;

/**
 * Aliases each app extension point (`TenantProvisionerInterface`, `ScimUserMapperInterface`, …)
 * to the app's single autoconfigured implementation, so apps only write the class. An explicit
 * alias in the app's services config wins; two implementations without one is an error.
 */
final class ExtensionPointAliasPass implements CompilerPassInterface
{
    public const TAG_PREFIX = 'sls_connector.extension_point.';

    /** @param array<string, class-string> $extensionPoints tag suffix => interface */
    public function __construct(private array $extensionPoints) {}

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->extensionPoints as $name => $interface) {
            if ($container->has($interface)) {
                continue;
            }
            $ids = array_keys(array_filter(
                $container->findTaggedServiceIds(self::TAG_PREFIX . $name),
                static fn (string $id): bool => !$container->getDefinition($id)->isAbstract(),
                ARRAY_FILTER_USE_KEY,
            ));
            if (count($ids) > 1) {
                throw new LogicException(sprintf('Several services implement "%s" (%s); alias the interface to the one SLS should use.', $interface, implode(', ', $ids)));
            }
            if ($ids !== []) {
                $container->setAlias($interface, $ids[0]);
            }
        }
    }
}
