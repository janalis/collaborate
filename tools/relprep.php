<?php

declare(strict_types=1);

/*
 * Prepares a release (see ReleasePrep): php tools/relprep.php --bump X.Y.Z|patch|minor|major [--notes file]
 * Run from the repository root; it updates CHANGELOG.md and prints `version=` and `tag=` lines.
 */

use Janalis\Collaborate\Tools\ReleasePrep;

require_once __DIR__ . '/ReleasePrep.php';

exit(new ReleasePrep()->run(array_values(array_slice($argv, 1)), STDOUT, STDERR));
