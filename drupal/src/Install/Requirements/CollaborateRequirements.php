<?php

declare(strict_types=1);

namespace Drupal\collaborate\Install\Requirements;

use Drupal\collaborate\Hub\MercureHubs;
use Drupal\collaborate\Storage\Connections;
use Drupal\Core\Extension\InstallRequirementsInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/** What the module needs of the site before it installs: a MariaDB or MySQL database, for its journal's SQL. */
final class CollaborateRequirements implements InstallRequirementsInterface
{
    /** @return array<string, array<string, mixed>> */
    public static function getRequirements(): array
    {
        return self::database();
    }

    /**
     * What the status report says once the module runs: its database, and the hub of the mercure transport, which
     * settings.php names.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function runtime(bool $mercure): array
    {
        $requirements = self::database();
        if ($mercure && !MercureHubs::configured()) {
            $requirements['collaborate_hub'] = [
                'title' => new TranslatableMarkup('Collaborate hub'),
                'value' => new TranslatableMarkup('Not configured'),
                'description' => new TranslatableMarkup(
                    'The pages hear of nothing until settings.php names the Mercure hub (<code>$settings[\'collaborate_mercure\']</code>), or chooses the WebSocket hub.',
                ),
                'severity' => RequirementSeverity::Warning,
            ];
        }

        return $requirements;
    }

    /** @return array<string, array<string, mixed>> */
    public static function database(): array
    {
        $driver = Connections::driver();
        if (\in_array($driver, Connections::DRIVERS, true)) {
            return [];
        }

        return [
            'collaborate_database' => [
                'title' => new TranslatableMarkup('Collaborate'),
                'value' => $driver,
                'description' => new TranslatableMarkup(
                    'Collaborate keeps its drafts on MariaDB 10.6+ or MySQL 8: the site\'s database is not one.',
                ),
                'severity' => RequirementSeverity::Error,
            ],
        ];
    }
}
