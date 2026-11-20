<?php

declare(strict_types=1);

namespace Drupal\collaborate\Install\Requirements;

use Drupal\collaborate\DependencyInjection\Options;
use Drupal\collaborate\Hub\MercureHubs;
use Drupal\collaborate\Storage\Connections;
use Drupal\Core\Extension\InstallRequirementsInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/** What the module needs of the site before it installs: a database its journal speaks to (MariaDB or MySQL, PostgreSQL, SQLite). */
final class CollaborateRequirements implements InstallRequirementsInterface
{
    /** @return array<string, array<string, mixed>> */
    public static function getRequirements(): array
    {
        return self::database();
    }

    /**
     * What the status report says once the module runs: its database, the hub of the mercure transport, which
     * settings.php names, and the options the site sets that the module reads nothing of.
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

        $ignored = Options::ignored();
        if ([] !== $ignored) {
            $requirements['collaborate_ignored_options'] = [
                'title' => new TranslatableMarkup('Collaborate options'),
                'value' => implode(', ', $ignored),
                'description' => new TranslatableMarkup(
                    'These options configure Symfony forms and Doctrine flushes, which the module replaces with Drupal\'s entity forms and hooks, or the module decides them itself: they change nothing here.',
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
                    'Collaborate keeps its drafts on MariaDB 10.6+, MySQL 8, PostgreSQL 13+ or SQLite 3.35+: the site\'s database is not one.',
                ),
                'severity' => RequirementSeverity::Error,
            ],
        ];
    }
}
