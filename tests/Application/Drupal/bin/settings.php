<?php

// The Collaborate test site's own settings, included by settings.php (`make drupal-install` appends the include):
// the Mercure hub FrankenPHP serves on the site's origin (tests/Application/Drupal/Caddyfile).

$hub = getenv('MERCURE_URL') ?: 'http://127.0.0.1:' . (getenv('DRUPAL_PORT') ?: '8790') . '/.well-known/mercure';
$settings['collaborate_mercure'] = [
  'url' => $hub,
  'jwt_secret' => getenv('MERCURE_JWT_SECRET') ?: '!ChangeThisMercureHubJWTSecretKey!',
  'claims' => [
    'iss' => 'collaborate-drupal',
    'sub' => 'collaborate-drupal',
    'client_id' => 'collaborate-drupal',
    'aud' => $hub,
  ],
];
