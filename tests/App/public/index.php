<?php

declare(strict_types=1);

use Smartlabsys\SlsConnectorBundle\Tests\App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

/*
 * Serve the demo app for an E2E run against a real SLS:
 *     cp tests/App/.env.dist tests/App/.env.local   # paste the SLS registration bundle
 *     php -S 127.0.0.1:8090 -t tests/App/public tests/App/public/index.php
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new Dotenv())->usePutenv(false)->loadEnv(dirname(__DIR__) . '/.env', 'APP_ENV', 'dev');

$kernel   = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$request  = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
