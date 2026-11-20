<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Unit;

use Janalis\Collaborate\CollaborateBundle;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

/**
 * docs/guide/configuration.md names each option's default: the one the configuration tree declares, from the
 * constants its classes read. A default changed in one place and not the other fails here.
 */
#[CoversNothing]
final class ConfigurationDocsTest extends TestCase
{
    public function testTheGuideNamesTheDefaultEveryOptionHas(): void
    {
        $extension = new CollaborateBundle()->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);
        $defaults = new Processor()->processConfiguration($configuration, [[]]);
        $checked = 0;
        $drifted = [];
        foreach ($this->documented() as $path => $default) {
            $value = $defaults;
            foreach (explode('.', $path) as $key) {
                if (!\is_array($value) || !\array_key_exists($key, $value)) {
                    continue 2;
                }
                // @mago-expect analysis:mixed-assignment a configuration value: the next lines narrow it
                $value = $value[$key];
            }
            if (\is_array($value)) {
                continue;
            }
            // A file mode reads in octal.
            if (preg_match('/^0[0-7]+$/D', $default)) {
                $default = (string) octdec($default);
            }
            $actual = match (true) {
                null === $value => 'null',
                true === $value => 'true',
                false === $value => 'false',
                default => (string) $value,
            };
            if ($default !== $actual) {
                $drifted[] = \sprintf('`%s`: the guide says %s, the tree %s', $path, $default, $actual);
            }
            ++$checked;
        }
        self::assertSame([], $drifted, 'docs/guide/configuration.md');
        self::assertGreaterThan(50, $checked, 'The guide\'s tables were read.');
    }

    /** @return array<string, string> the option path => the first code span of its default column */
    private function documented(): array
    {
        $lines = file(\dirname(__DIR__, 2) . '/docs/guide/configuration.md', \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $section = null;
        $documented = [];
        foreach ($lines as $line) {
            $heading = [];
            if (1 === preg_match('/^#{2,3} (?:`([a-z_.]+)`|Top level)$/', $line, $heading)) {
                $section = $heading[1] ?? '';
                continue;
            }
            if (1 === preg_match('/^#{1,3} /', $line)) {
                $section = null;
                continue;
            }
            $row = [];
            if (null === $section || 1 !== preg_match('/^\| `([a-z_.]+)` \| `([^`]*)`/', $line, $row)) {
                continue;
            }
            // A pipe in a table cell is escaped.
            $documented['' === $section ? $row[1] : $section . '.' . $row[1]] = strtr($row[2], ['\\|' => '|']);
        }

        return $documented;
    }
}
