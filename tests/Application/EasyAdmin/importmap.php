<?php

declare(strict_types=1);

/* The EasyAdmin application's import map; see ../Shared/importmap.php for the shared entries. */
/** @var array<string, array{path: string}> $shared */
$shared = require dirname(__DIR__) . '/Shared/importmap.php';

return [
    'admin' => ['path' => './assets/admin.js', 'entrypoint' => true],
] + $shared;
