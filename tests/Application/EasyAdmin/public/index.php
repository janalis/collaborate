<?php

declare(strict_types=1);

use Janalis\Collaborate\Tests\Application\EasyAdmin\EasyAdminKernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$debug = filter_var($_SERVER['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOL);
$kernel = new EasyAdminKernel($_SERVER['APP_ENV'] ?? 'e2e', $debug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
