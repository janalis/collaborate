<?php

declare(strict_types=1);

/*
 * Append bench of the draft journal (not run in CI): a draft whose collection holds `--members` entries takes
 * `--appends` value edits of one entry's field, each through DraftJournal::append() and its named lock, on the
 * database COLLABORATE_DATABASE_URL names (docs/schema.sql's tables). Reports the median and p95 of an append.
 *
 *   COLLABORATE_DATABASE_URL=mysql://user@localhost:3306/collaborate_test php tests/bench/journal-append.php --members=2000
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Janalis\Collaborate\Draft\DraftJournal;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['members::', 'appends::']);
$members = (int) ($options['members'] ?? 2000);
$appends = (int) ($options['appends'] ?? 300);
$url = getenv('COLLABORATE_DATABASE_URL');
if (!is_string($url) || '' === $url) {
    fwrite(STDERR, "Set COLLABORATE_DATABASE_URL.\n");
    exit(1);
}
$db = DriverManager::getConnection(new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql'])->parse($url));
$journal = new DraftJournal($db, firstGeneration: 1, limits: ['max_draft_bytes' => 1 << 30]);
$id = 'bench-append-' . bin2hex(random_bytes(4));

$fields = ['items[__collection__]' => [
    'type' => 'collection',
    'initial' => array_map('strval', range(0, $members - 1)),
]];
for ($key = 0; $key < $members; ++$key) {
    $fields["items[@{$key}]"] = [
        'type' => 'member',
        'initial' => ['alive' => true, 'order' => $key, 'recordId' => (string) $key],
    ];
    $fields["items[{$key}][title]"] = ['type' => 'value', 'initial' => "Title {$key}"];
}
$field = 'items[' . intdiv($members, 2) . '][title]';

try {
    $journal->join($id, $fields);
    $times = [];
    for ($n = 0; $n < $appends; ++$n) {
        $event = [
            'id' => sprintf('%08x-bench-%016x', $n, random_int(0, PHP_INT_MAX)),
            'generation' => 1,
            'seen' => 0,
            'field' => $field,
            'kind' => 'value',
            'version' => $n,
            'value' => "Edit {$n}",
        ];
        $start = hrtime(true);
        $journal->append($id, [$field => $fields[$field]], $event, 1, 'Bench');
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);
    printf(
        "members=%d appends=%d median=%.3f ms p95=%.3f ms\n",
        $members,
        $appends,
        $times[intdiv(count($times), 2)],
        $times[(int) floor(count($times) * 0.95)],
    );
} finally {
    foreach ([
        'collaborate_draft_event' => 'draft_id',
        'collaborate_draft_field' => 'draft_id',
        'collaborate_draft' => 'id',
    ] as $table => $column) {
        $db->delete($table, [$column => $id]);
    }
}
