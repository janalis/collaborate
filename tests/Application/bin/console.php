<?php

declare(strict_types=1);

/*
 * The test applications' console: `COLLABORATE_APP=plain|easyadmin|host APP_ENV=e2e php tests/Application/bin/console.php app:setup`.
 */

use Janalis\Collaborate\Tests\Application\EasyAdmin\EasyAdminKernel;
use Janalis\Collaborate\Tests\Application\Host\HostKernel;
use Janalis\Collaborate\Tests\Application\Plain\PlainKernel;
use Janalis\Collaborate\Tests\Application\Shared\Environment;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$environment = Environment::get('APP_ENV') ?? 'e2e';
$kernel = match (Environment::get('COLLABORATE_APP') ?? 'plain') {
    'plain' => new PlainKernel($environment, false),
    'easyadmin' => new EasyAdminKernel($environment, false),
    'host' => new HostKernel($environment, false),
    default => throw new InvalidArgumentException('COLLABORATE_APP is "plain", "easyadmin" or "host".'),
};
exit(new Application($kernel)->run());
