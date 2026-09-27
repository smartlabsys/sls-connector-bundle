<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle;

use Smartlabsys\SlsConnectorBundle\DependencyInjection\ExtensionPointAliasPass;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * SLS connector (SLS app contract v1): manifest, service-token and SSO authentication,
 * back-channel logout, provisioning API, SCIM 2.0 server, webhook receiver and `SlsClient`.
 *
 * Apps configure it under `sls_connector:`, import `@SlsConnectorBundle/config/routes.php`
 * and wire the security handlers into their firewalls (see README.md).
 */
final class SlsConnectorBundle extends AbstractBundle
{
    public const CONTRACT_VERSION = 1;

    private const APP_KEY_PATTERN = '/^[a-z][a-z0-9_-]{1,31}$/';

    protected string $extensionAlias = 'sls_connector';

    /** Interfaces an app implements; each is aliased to its single implementation. */
    private const EXTENSION_POINTS = [
        'tenant_provisioner' => Provisioning\TenantProvisionerInterface::class,
        'seed_handler'       => Provisioning\SeedHandlerInterface::class,
        'scim_user_mapper'   => Scim\ScimUserMapperInterface::class,
        'scim_group_mapper'  => Scim\ScimGroupMapperInterface::class,
        'user_resolver'      => Security\SlsUserResolverInterface::class,
    ];

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ExtensionPointAliasPass(self::EXTENSION_POINTS));
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $root */
        $root = $definition->rootNode();

        $root
            ->children()
                ->scalarNode('issuer')->isRequired()->cannotBeEmpty()->info('SLS_ISSUER, e.g. https://platform.smartlabsys.com')->end()
                ->scalarNode('instance_id')->defaultNull()->info('SLS_INSTANCE_ID')->end()
                ->scalarNode('audience')->isRequired()->cannotBeEmpty()->info('SLS_AUDIENCE — this instance\'s base URL')->end()
                ->scalarNode('client_id')->isRequired()->cannotBeEmpty()->info('SLS_CLIENT_ID')->end()
                ->scalarNode('client_secret')->isRequired()->cannotBeEmpty()->info('SLS_CLIENT_SECRET')->end()
                ->scalarNode('webhook_secret')->isRequired()->cannotBeEmpty()->info('SLS_WEBHOOK_SECRET')->end()
                ->arrayNode('app')
                    ->isRequired()
                    ->children()
                        ->scalarNode('key')->isRequired()
                            ->validate()->ifTrue(static fn ($v) => !is_string($v) || !preg_match(self::APP_KEY_PATTERN, $v))
                                ->thenInvalid('The app key %s must match ' . self::APP_KEY_PATTERN . '.')->end()
                        ->end()
                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('version')->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('roles')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('key')->isRequired()->cannotBeEmpty()->end()
                            ->append($this->labelNode('label', true))
                            ->append($this->labelNode('description', false))
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('seed_templates')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('key')->isRequired()->cannotBeEmpty()->end()
                            ->integerNode('version')->isRequired()->min(1)->end()
                            ->append($this->labelNode('label', true))
                            ->append($this->labelNode('description', false))
                            ->variableNode('parameters')->defaultValue([])
                                ->validate()->ifTrue(static fn ($v) => !is_array($v) || !array_is_list($v))
                                    ->thenInvalid('Seed template parameters must be a list.')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('events')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('emits')->scalarPrototype()->end()->end()
                        ->arrayNode('consumes')->scalarPrototype()->end()->end()
                    ->end()
                ->end()
                ->arrayNode('endpoints')
                    ->addDefaultsIfNotSet()
                    ->info('The optional manifest endpoints — absolute paths on this app, or null.')
                    ->children()
                        ->scalarNode('api')->defaultNull()->end()
                        ->scalarNode('mcp')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('api')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('accept_app_tokens')->defaultFalse()->info('Accept sibling apps calling as themselves (SlsAppUser, ROLE_SLS_APP) on the user-token firewall.')->end()
                    ->end()
                ->end()
                ->arrayNode('oidc')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('scopes')->defaultValue('openid profile email org apps')->end()
                        ->scalarNode('default_target_path')->defaultValue('/')->end()
                        ->scalarNode('failure_path')->defaultValue('/login')->end()
                        ->booleanNode('rp_logout')->defaultTrue()->info('On local logout, also end the SLS session (RP-initiated logout).')->end()
                    ->end()
                ->end()
                ->scalarNode('jwks_file')->defaultNull()->info('Read the SLS signing keys from this JWKS file instead of fetching them (air-gapped installs, contract tests).')->end()
                ->scalarNode('cache_pool')->defaultValue('cache.app')->end()
                ->scalarNode('http_client')->defaultValue('http_client')->end()
            ->end()
            ->validate()
                ->always(static function (array $config): array {
                    $prefix = $config['app']['key'] . ':';
                    foreach ($config['roles'] as $role) {
                        if (!str_starts_with($role['key'], $prefix)) {
                            throw new \InvalidArgumentException(sprintf('Role key "%s" must start with "%s".', $role['key'], $prefix));
                        }
                    }
                    foreach ($config['endpoints'] as $name => $path) {
                        if ($path !== null && !str_starts_with($path, '/')) {
                            throw new \InvalidArgumentException(sprintf('Endpoint "%s" must be an absolute path.', $name));
                        }
                    }

                    return $config;
                })
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()
            ->set('sls_connector.config', $config)
            ->set('sls_connector.issuer', rtrim($config['issuer'], '/'))
            ->set('sls_connector.audience', rtrim($config['audience'], '/'))
            ->set('sls_connector.client_id', $config['client_id'])
            ->set('sls_connector.client_secret', $config['client_secret'])
            ->set('sls_connector.webhook_secret', $config['webhook_secret'])
            ->set('sls_connector.jwks_file', $config['jwks_file'])
            ->set('sls_connector.oidc', $config['oidc'])
            ->set('sls_connector.oidc.scopes', $config['oidc']['scopes'])
            ->set('sls_connector.oidc.rp_logout', $config['oidc']['rp_logout'])
            ->set('sls_connector.api.accept_app_tokens', $config['api']['accept_app_tokens']);

        $container->import('../config/services.php');

        foreach (self::EXTENSION_POINTS as $name => $interface) {
            $builder->registerForAutoconfiguration($interface)->addTag(ExtensionPointAliasPass::TAG_PREFIX . $name);
        }

        $builder->setAlias('sls_connector.cache', $config['cache_pool']);
        $builder->setAlias('sls_connector.http_client', $config['http_client']);

        if (!class_exists(\Twig\Environment::class)) {
            $builder->removeDefinition(Twig\SlsConnectorExtension::class);
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    private function labelNode(string $name, bool $required): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node->useAttributeAsKey('locale')->scalarPrototype()->end();
        if ($required) {
            $node->isRequired()
                ->validate()->ifTrue(static fn ($v) => !isset($v['en']) || trim((string) $v['en']) === '')
                    ->thenInvalid('A label needs at least an "en" entry.')->end();
        }

        return $node;
    }
}
