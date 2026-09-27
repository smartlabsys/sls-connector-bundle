<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->bind('string $issuer', '%sls_connector.issuer%')
            ->bind('string $audience', '%sls_connector.audience%')
            ->bind('string $clientId', '%sls_connector.client_id%')
            ->bind('string $clientSecret', '%sls_connector.client_secret%')
            ->bind('$jwksFile', '%sls_connector.jwks_file%')
            ->bind('array $config', '%sls_connector.config%')
            ->bind('array $oidcConfig', '%sls_connector.oidc%')
            ->bind('string $scopes', '%sls_connector.oidc.scopes%')
            ->bind('bool $enabled', '%sls_connector.oidc.rp_logout%')
            ->bind(CacheItemPoolInterface::class, service('sls_connector.cache'))
            ->bind(HttpClientInterface::class, service('sls_connector.http_client'));

    $services->load('Smartlabsys\\SlsConnectorBundle\\', '../src/')
        ->exclude([
            '../src/SlsConnectorBundle.php',
            '../src/Exception/',
            '../src/Test/',
            '../src/Webhook/',
            '../src/Provisioning/Model/',
            '../src/Scim/Model/',
            '../src/Scim/ScimFilter.php',
            '../src/Scim/ScimPatch.php',
            '../src/Scim/ScimSchemas.php',
            '../src/Security/SlsIdentity.php',
            '../src/Security/SlsServiceUser.php',
            '../src/Client/SlsTokenException.php',
            '../src/Jwt/InvalidTokenException.php',
        ]);

    $services->load('Smartlabsys\\SlsConnectorBundle\\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments');
};
