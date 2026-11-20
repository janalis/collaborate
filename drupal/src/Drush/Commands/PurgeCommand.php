<?php

declare(strict_types=1);

namespace Drupal\collaborate\Drush\Commands;

use Drupal\collaborate\Drush\BundleCommandProxy;
use Janalis\Collaborate\Command\PurgeDraftsCommand as BundleCommand;

/** `drush collaborate:purge`: the bundle's command, as the module registers it; a reminder of what it needs when that is off. */
final class PurgeCommand extends BundleCommandProxy
{
    protected static function bundleCommand(): string
    {
        return BundleCommand::class;
    }

    protected static function commandName(): string
    {
        return 'collaborate:purge';
    }

    protected static function needs(): string
    {
        return 'shared drafts';
    }
}
