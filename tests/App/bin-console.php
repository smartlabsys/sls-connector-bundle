<?php

declare(strict_types=1);

// Console for the demo app: php tests/App/bin-console.php sls:connector:warmup
use Smartlabsys\SlsConnectorBundle\Tests\App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Dotenv())->usePutenv(false)->loadEnv(__DIR__ . '/.env', 'APP_ENV', 'dev');

(new Application(new Kernel($_SERVER['APP_ENV'] ?? 'dev', true)))->run();
