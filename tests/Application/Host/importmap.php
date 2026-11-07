<?php

declare(strict_types=1);

/*
 * The host application's import map: its page script, the bundle's cursors alone (no draft, no CKEditor) and
 * the bare import they expect.
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@janalis/collaborate/cursors/cursors.js' => ['path' => '@janalis/collaborate/cursors/cursors.js'],
    '@janalis/collaborate/notifications/bell.js' => ['path' => '@janalis/collaborate/notifications/bell.js'],
    '@janalis/collaborate/notifications/page.js' => ['path' => '@janalis/collaborate/notifications/page.js'],
    '@janalis/collaborate/notifications/topics.js' => ['path' => '@janalis/collaborate/notifications/topics.js'],
    '@microsoft/fetch-event-source' => ['path' => 'build/fetch-event-source/index.js'],
];
