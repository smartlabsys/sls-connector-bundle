<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * The contract endpoints. Import in the app:
 *
 *     # config/routes/sls_connector.yaml
 *     sls_connector:
 *         resource: '@SlsConnectorBundle/config/routes.php'
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('../src/Controller/', 'attribute');
};
