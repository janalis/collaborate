<?php

declare(strict_types=1);

namespace Drupal\collaborate\Drush\Commands;

use Drupal\collaborate\Drush\BundleCommandProxy;
use Janalis\Collaborate\Command\HubServeCommand as BundleCommand;

/** `drush collaborate:hub:serve`: the bundle's command, as the module registers it; a reminder of what it needs when that is off. */
final class HubServeCommand extends BundleCommandProxy
{
    protected static function bundleCommand(): string
    {
        return BundleCommand::class;
    }

    protected static function commandName(): string
    {
        return 'collaborate:hub:serve';
    }

    protected static function needs(): string
    {
        return 'the websocket hub transport';
    }

    protected static function hint(): string
    {
        return '$settings[\'collaborate\'][\'hub\'][\'transport\'] = \'websocket\' in settings.php';
    }
}
