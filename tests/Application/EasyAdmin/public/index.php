<?php

declare(strict_types=1);

use Janalis\Collaborate\Tests\Application\EasyAdmin\EasyAdminKernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$kernel = new EasyAdminKernel($_SERVER['APP_ENV'] ?? 'e2e', '1' === ($_SERVER['APP_DEBUG'] ?? '0'));
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
