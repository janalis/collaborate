<?php

declare(strict_types=1);

/*
 * The plain application's import map. Logical paths are the asset mapper paths AppKernel maps: the bundle
 * (`@janalis/collaborate`), the shared page scripts (`shared/`), node_modules (`vendor/`) and the files the
 * setup script generates (`build/`, tests/Application/var/assets).
 */
/** @var array<string, array{path: string}> $shared */
$shared = require dirname(__DIR__) . '/Shared/importmap.php';

return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
] + $shared;
