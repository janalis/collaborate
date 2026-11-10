<?php

declare(strict_types=1);

/*
 * Watches the Drupal module's drupal.org project for GitHub (see DrupalOrg\Watcher):
 * php tools/drupalorg.php mrs|issues|releases|closed <pr>|release-issue <version> <notes> [--dry-run]
 * Run from a checkout of the repository, with `gh` signed in (GH_TOKEN); DRUPALORG_GITLAB_TOKEN lets it write to
 * the merge requests.
 */

use Janalis\Collaborate\Tools\DrupalOrg\CurlHttp;
use Janalis\Collaborate\Tools\DrupalOrg\ProcessRunner;
use Janalis\Collaborate\Tools\DrupalOrg\Watcher;

foreach (['Http', 'CurlHttp', 'Runner', 'ProcessRunner', 'DrupalOrg', 'GitHub', 'Watcher'] as $class) {
    require_once __DIR__ . '/DrupalOrg/' . $class . '.php';
}

$environment = array_filter(
    ['GITHUB_REPOSITORY' => getenv('GITHUB_REPOSITORY'), 'DRUPALORG_GITLAB_TOKEN' => getenv('DRUPALORG_GITLAB_TOKEN')],
    is_string(...),
);

exit(new Watcher(new CurlHttp(), new ProcessRunner(), $environment)->run(
    array_values(array_slice($argv, 1)),
    STDOUT,
    STDERR,
));
