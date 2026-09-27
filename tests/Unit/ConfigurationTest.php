<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Provisioning\TenantProvisionerInterface;
use Smartlabsys\SlsConnectorBundle\SlsConnectorBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfigurationTest extends TestCase
{
    public function testValidConfigSetsParametersAndDefaults(): void
    {
        $container = $this->load($this->config());

        self::assertSame('https://sls.test', $container->getParameter('sls_connector.issuer'), 'trailing slash trimmed');
        self::assertSame('http://app.test', $container->getParameter('sls_connector.audience'));
        $config = $container->getParameter('sls_connector.config');
        self::assertSame('openid profile email org apps', $config['oidc']['scopes']);
        self::assertTrue($config['oidc']['rp_logout']);
        self::assertSame(['api' => '/api', 'mcp' => null], $config['endpoints']);
        self::assertTrue($container->hasAlias('sls_connector.cache'));
    }

    public function testRegistersExtensionPointAutoconfiguration(): void
    {
        $container = $this->load($this->config());

        self::assertArrayHasKey(TenantProvisionerInterface::class, $container->getAutoconfiguredInstanceof());
    }

    public function testRejectsInvalidAppKey(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load($this->config(['app' => ['key' => 'Bad Key', 'name' => 'Demo', 'version' => '1']]));
    }

    public function testRejectsRoleWithoutAppPrefix(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must start with "demo:"');
        $this->load($this->config(['roles' => [['key' => 'admin', 'label' => ['en' => 'Admin']]]]));
    }

    public function testRejectsRoleWithoutEnglishLabel(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load($this->config(['roles' => [['key' => 'demo:admin', 'label' => ['sr' => 'Admin']]]]));
    }

    public function testRejectsRelativeEndpoint(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load($this->config(['endpoints' => ['api' => 'api']]));
    }

    public function testMcpEndpointIsCheckedLikeTheOthers(): void
    {
        $config = $this->load($this->config(['endpoints' => ['api' => '/api', 'mcp' => '/mcp']]))->getParameter('sls_connector.config');
        self::assertSame(['api' => '/api', 'mcp' => '/mcp'], $config['endpoints']);

        $this->expectException(InvalidConfigurationException::class);
        $this->load($this->config(['endpoints' => ['mcp' => 'mcp']]));
    }

    public function testRejectsMissingSecrets(): void
    {
        $config = $this->config();
        unset($config['webhook_secret']);
        $this->expectException(InvalidConfigurationException::class);
        $this->load($config);
    }

    /** @param array<string, mixed> $overrides */
    private function config(array $overrides = []): array
    {
        return $overrides + [
            'issuer'         => 'https://sls.test/',
            'audience'       => 'http://app.test/',
            'client_id'      => 'demo-client',
            'client_secret'  => 'secret',
            'webhook_secret' => 'whsec',
            'app'            => ['key' => 'demo', 'name' => 'Demo', 'version' => '1.0.0'],
            'roles'          => [['key' => 'demo:admin', 'label' => ['en' => 'Admin', 'sr' => 'Administrator']]],
            'endpoints'      => ['api' => '/api'],
        ];
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.debug', false);
        (new SlsConnectorBundle())->getContainerExtension()->load([$config], $container);

        return $container;
    }
}
