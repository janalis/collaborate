<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Draft;

/**
 * The fields one form may write and read, found by name: its own fields, and the entries of its collections
 * through the prototypes whose placeholders their names fill. The journal builds one for each call that names
 * a form's fields, so the patterns a call matches every field and every event against are compiled once, and
 * no later call, which may bring another form's fields, can find them stale.
 *
 * @internal
 *
 * @phpstan-import-type SchemaField from DraftJournal
 */
final class FieldDefinitions
{
    /** @var list<array{string, string, SchemaField}> each prototype's expression, its name and its definition, in the form's order */
    private array $prototypes = [];

    /** @param array<string, SchemaField> $allowed */
    public function __construct(
        private readonly array $allowed,
    ) {
        foreach ($allowed as $pattern => $field) {
            if (!self::prototype($pattern)) {
                continue;
            }
            $this->prototypes[] = [
                '#^'
                    . str_replace(
                        ['__name__', '__member__'],
                        ['[a-zA-Z0-9_-]{1,80}', '@[a-zA-Z0-9_-]{1,80}'],
                        preg_quote($pattern, '#'),
                    )
                    . '$#D',
                $pattern,
                $field,
            ];
        }
    }

    /** A collection's entry template names its placeholders literally; no edit targets the template itself. */
    public static function prototype(string $name): bool
    {
        return str_contains($name, '__name__') || str_contains($name, '__member__');
    }

    /** @return SchemaField|null */
    public function definition(string $name): ?array
    {
        if (isset($this->allowed[$name])) {
            return $this->allowed[$name];
        }
        foreach ($this->prototypes as [$expression, $pattern, $field]) {
            if (1 !== preg_match($expression, $name)) {
                continue;
            }
            if (isset($field['formName'])) {
                $field['formName'] = substr($name, \strlen($pattern) - \strlen($field['formName']));
            }

            return $field;
        }

        return null;
    }
}
