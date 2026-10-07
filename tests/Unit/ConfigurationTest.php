<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Smartlabsys\SlsConnectorBundle\Manifest\ManifestBuilder;
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
        self::assertTrue($container->getParameter('sls_connector.api.introspect_partner_tokens'), 'partner tokens are introspected by default (0.3.3)');
        self::assertFalse($this->load($this->config(['api' => ['introspect_partner_tokens' => false]]))->getParameter('sls_connector.api.introspect_partner_tokens'));
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

    public function testIntegrationIsEmptyByDefaultAndReachesTheManifest(): void
    {
        $config = $this->load($this->config())->getParameter('sls_connector.config');
        self::assertSame(['provides' => [], 'uses' => [], 'partnership_roles' => []], $config['integration']);

        $config = $this->load($this->config(['integration' => [
            'provides' => [['scope' => 'demo:orders.read', 'label' => ['en' => 'Read orders']]],
            'uses'     => [['app' => 'qc', 'scopes' => ['qc:requests.write']]],
        ]]))->getParameter('sls_connector.config');
        $manifest = (new ManifestBuilder($config))->build();
        self::assertSame([
            'provides' => [['scope' => 'demo:orders.read', 'label' => ['en' => 'Read orders']]],
            'uses'     => [['app' => 'qc', 'scopes' => ['qc:requests.write']]],
        ], $manifest['integration']);
    }

    public function testRejectsProvidedScopeOfAnotherApp(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Provided scope "qc:orders.read"');
        $this->load($this->config(['integration' => ['provides' => [['scope' => 'qc:orders.read', 'label' => ['en' => 'Read']]]]]));
    }

    public function testRejectsProvidedScopeWithoutAction(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load($this->config(['integration' => ['provides' => [['scope' => 'demo:orders', 'label' => ['en' => 'Read']]]]]));
    }

    public function testRejectsUsingOwnApp(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('not a sibling app key');
        $this->load($this->config(['integration' => ['uses' => [['app' => 'demo', 'scopes' => ['demo:orders.read']]]]]));
    }

    public function testRejectsUsedScopeOfAnotherApp(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Used scope "lims:requests.read"');
        $this->load($this->config(['integration' => ['uses' => [['app' => 'qc', 'scopes' => ['lims:requests.read']]]]]));
    }

    public function testPartnershipRolesReachTheManifest(): void
    {
        $config = $this->load($this->config())->getParameter('sls_connector.config');
        self::assertSame([], $config['integration']['partnership_roles']);
        self::assertArrayNotHasKey('partnership_roles', (new ManifestBuilder($config))->build()['integration'], 'not emitted when empty');

        $config = $this->load($this->config(['integration' => self::partnerIntegration()]))->getParameter('sls_connector.config');
        self::assertSame([[
            'key'             => 'demo:laboratory',
            'label'           => ['en' => 'Laboratory', 'sr' => 'Laboratorija'],
            'provider_apps'   => ['lims'],
            'provider_scopes' => ['demo:orders.read'],
            'customer_scopes' => ['lims:requests.read'],
        ]], (new ManifestBuilder($config))->build()['integration']['partnership_roles']);
    }

    public function testClaimCodeFlagReachesTheManifestOnlyWhenSet(): void
    {
        $config = $this->load($this->config())->getParameter('sls_connector.config');
        self::assertSame(['claim_code' => false], $config['tenants']);
        self::assertArrayNotHasKey('tenants', (new ManifestBuilder($config))->build(), 'not emitted by default');

        $config = $this->load($this->config(['tenants' => ['claim_code' => true]]))->getParameter('sls_connector.config');
        self::assertSame(['claim_code' => true], (new ManifestBuilder($config))->build()['tenants']);
    }

    public function testRejectsPartnershipRoleOfAnotherApp(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Partnership role key "qc:laboratory"');
        $this->load($this->config(['integration' => self::partnerIntegration(['key' => 'qc:laboratory'])]));
    }

    public function testRejectsProviderScopeNotProvided(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Provider scope "demo:orders.write"');
        $this->load($this->config(['integration' => self::partnerIntegration(['provider_scopes' => ['demo:orders.write']])]));
    }

    public function testRejectsPartnershipRoleWithoutProviderScopes(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load($this->config(['integration' => self::partnerIntegration(['provider_scopes' => []])]));
    }

    public function testRejectsOwnScopeAsCustomerScope(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Customer scope "demo:orders.read"');
        $this->load($this->config(['integration' => self::partnerIntegration(['customer_scopes' => ['demo:orders.read']])]));
    }

    public function testRejectsCustomerScopeOfAnAppNotAllowedToProvide(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Customer scope "financial:invoices.read"');
        $this->load($this->config(['integration' => self::partnerIntegration(['customer_scopes' => ['financial:invoices.read']])]));
    }

    /**
     * @param array<string, mixed> $role overrides
     *
     * @return array<string, mixed>
     */
    private static function partnerIntegration(array $role = []): array
    {
        return [
            'provides'          => [['scope' => 'demo:orders.read', 'label' => ['en' => 'Read orders']]],
            'partnership_roles' => [$role + [
                'key'             => 'demo:laboratory',
                'label'           => ['en' => 'Laboratory', 'sr' => 'Laboratorija'],
                'provider_apps'   => ['lims'],
                'provider_scopes' => ['demo:orders.read'],
                'customer_scopes' => ['lims:requests.read'],
            ]],
        ];
    }

    public function testEventsReachTheConfig(): void
    {
        $events = ['emits' => ['demo.order.created', 'legacy_event.done'], 'consumes' => ['qc.request.created', 'company.updated']];
        $config = $this->load($this->config(['events' => $events]))->getParameter('sls_connector.config');

        self::assertSame($events, $config['events'], 'unprefixed emits are accepted (never brokered)');
    }

    public function testRejectsMalformedEventType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Event type "Order Created" in events.emits');
        $this->load($this->config(['events' => ['emits' => ['Order Created']]]));
    }

    public function testRejectsUndottedConsumedType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('events.consumes');
        $this->load($this->config(['events' => ['consumes' => ['created']]]));
    }

    public function testRejectsTooLongEventType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load($this->config(['events' => ['emits' => ['demo.' . str_repeat('x', 60)]]]));
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
