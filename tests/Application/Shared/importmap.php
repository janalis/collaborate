<?php

declare(strict_types=1);

/* The entries both applications' import maps share: the bundle's modules and the bare imports it expects. */
return [
    'editors' => ['path' => 'shared/editors.js'],
    '@janalis/collaborate/easyadmin.js' => ['path' => '@janalis/collaborate/easyadmin.js'],
    '@janalis/collaborate/ckeditor5/collaborate.js' => ['path' => '@janalis/collaborate/ckeditor5/collaborate.js'],
    'ckeditor5' => ['path' => 'vendor/ckeditor5/ckeditor5.js'],
    '@microsoft/fetch-event-source' => ['path' => 'build/fetch-event-source/index.js'],
    'bootstrap/js/src/modal.js' => ['path' => 'vendor/bootstrap/modal.js'],
];
