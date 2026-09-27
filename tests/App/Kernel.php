<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App;

use Smartlabsys\SlsConnectorBundle\Security\SlsBearerChallenge;
use Smartlabsys\SlsConnectorBundle\Security\SlsOidcAuthenticator;
use Smartlabsys\SlsConnectorBundle\Security\SlsServiceTokenHandler;
use Smartlabsys\SlsConnectorBundle\Security\SlsUserTokenHandler;
use Smartlabsys\SlsConnectorBundle\SlsConnectorBundle;
use Smartlabsys\SlsConnectorBundle\Tests\App\Security\DemoUserProvider;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Demo app for the bundle's own tests and for E2E runs against a real SLS: every bundle
 * interface implemented over a JSON file (`var/<env>/store.json`).
 *
 * `test` env: fixed SLS settings and a local JWKS file (the contract suite writes it).
 * `dev` env: the SLS_* registration bundle from the environment (see public/index.php).
 * `dev2` env: a second app (`demo2`, own store / session cookie) to try sibling calls (doc 09).
 * `/mcp` is a minimal MCP server (a `whoami` tool) behind SLS user tokens (doc 09 §4).
 * `DEMO_SEED_ASYNC=1` leaves seed jobs queued until `demo:seed:complete` finishes them.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new SlsConnectorBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $test = $this->environment === 'test';
        $key  = $this->environment === 'dev2' ? 'demo2' : 'demo';

        $container->parameters()->set('env(DEMO_SEED_ASYNC)', '0');

        $container->extension('framework', [
            'secret'               => 'demo-secret',
            'test'                 => $test,
            'http_method_override' => false,
            'session'              => ['name' => strtoupper($key) . 'SESSID', 'handler_id' => null, 'cookie_secure' => 'auto', 'cookie_samesite' => 'lax', 'storage_factory_id' => $test ? 'session.storage.factory.mock_file' : 'session.storage.factory.native'],
            'router'               => ['utf8' => true],
            'default_locale'       => 'en',
            'translator'           => ['default_path' => '%kernel.project_dir%/translations', 'fallbacks' => ['en']],
            'php_errors'           => ['log' => true],
            'http_client'          => $test ? ['mock_response_factory' => Sls\MockSlsResponses::class] : [],
        ]);
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates']);

        $container->extension('sls_connector', [
            'issuer'         => $test ? 'https://sls.test' : '%env(SLS_ISSUER)%',
            'instance_id'    => $test ? 'test-instance' : '%env(SLS_INSTANCE_ID)%',
            'audience'       => $test ? 'http://localhost' : '%env(SLS_AUDIENCE)%',
            'client_id'      => $test ? 'demo-client' : '%env(SLS_CLIENT_ID)%',
            'client_secret'  => $test ? 'demo-client-secret' : '%env(SLS_CLIENT_SECRET)%',
            'webhook_secret' => $test ? 'demo-webhook-secret' : '%env(SLS_WEBHOOK_SECRET)%',
            'app'            => ['key' => $key, 'name' => $key === 'demo' ? 'Connector Demo' : 'Connector Demo 2', 'version' => '1.0.0'],
            'roles'          => [
                ['key' => $key . ':admin', 'label' => ['en' => 'Demo admin', 'sr' => 'Demo administrator']],
                ['key' => $key . ':viewer', 'label' => ['en' => 'Viewer', 'sr' => 'Posmatrač']],
            ],
            'seed_templates' => [[
                'key'         => 'demo-basic',
                'version'     => 2,
                'label'       => ['en' => 'Demo – basic', 'sr' => 'Demo – osnovno'],
                'parameters'  => [['key' => 'lab_name', 'type' => 'string']],
            ]],
            'endpoints'      => ['api' => '/api', 'mcp' => '/mcp'],
            'api'            => ['accept_app_tokens' => true],
            'oidc'           => ['default_target_path' => '/', 'failure_path' => '/login'],
            'jwks_file'      => $test ? '%kernel.project_dir%/var/test/sls-jwks.json' : null,
        ]);

        $container->extension('security', [
            'providers' => ['demo' => ['id' => DemoUserProvider::class]],
            'firewalls' => [
                'sls_service' => [
                    'pattern'      => '^/(sls/(health|provisioning)|scim/v2)',
                    'stateless'    => true,
                    'access_token' => ['token_handler' => SlsServiceTokenHandler::class],
                ],
                'api' => [
                    'pattern'      => '^/api',
                    'stateless'    => true,
                    'access_token' => ['token_handler' => SlsUserTokenHandler::class],
                ],
                'mcp' => [
                    'pattern'      => '^/mcp',
                    'stateless'    => true,
                    'access_token' => ['token_handler' => SlsUserTokenHandler::class, 'failure_handler' => SlsBearerChallenge::class],
                    'entry_point'  => SlsBearerChallenge::class,
                ],
                'main' => [
                    'lazy'                  => true,
                    'custom_authenticators' => [SlsOidcAuthenticator::class],
                    'entry_point'           => 'security.demo_login_entry_point',
                    'logout'                => ['path' => '/logout', 'target' => '/login'],
                ],
            ],
            'access_control' => [
                ['path' => '^/(api|mcp)', 'roles' => 'IS_AUTHENTICATED_FULLY'],
                ['path' => '^/(account|siblings)', 'roles' => 'ROLE_USER'],
            ],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load(__NAMESPACE__ . '\\', __DIR__ . '/src/');
        $services->load(__NAMESPACE__ . '\\Controller\\', __DIR__ . '/src/Controller/')->tag('controller.service_arguments');
        $services->set(Store\JsonStore::class)->arg('$file', '%kernel.project_dir%/var/%kernel.environment%/store.json');
        $services->set(Controller\DemoController::class)->arg('$appKey', $key)->tag('controller.service_arguments');
        $services->set(Controller\McpController::class)->arg('$appKey', $key)->tag('controller.service_arguments');
        $services->set(Sls\DemoSeedHandler::class)->arg('$async', '%env(bool:DEMO_SEED_ASYNC)%');
        $services->set('security.demo_login_entry_point', Security\LoginEntryPoint::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@SlsConnectorBundle/config/routes.php');
        $routes->import(__DIR__ . '/src/Controller/', 'attribute');
        $routes->add('logout', '/logout')->methods(['GET']);
    }
}
