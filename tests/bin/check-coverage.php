<?php

declare(strict_types=1);

/*
 * Fails unless the Clover report covers every statement and method: php tests/bin/check-coverage.php var/coverage/clover.xml
 */

$file = $argv[1] ?? 'var/coverage/clover.xml';
$document = new DOMDocument();
if (!is_file($file) || !$document->load($file)) {
    fwrite(STDERR, "No Clover report at {$file}.\n");
    exit(2);
}
$xpath = new DOMXPath($document);

/** @return list<DOMElement> the elements of a query's result, none when it failed */
$found = static function (mixed $nodes): array {
    if (!$nodes instanceof DOMNodeList) {
        return [];
    }
    $elements = [];
    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $elements[] = $node;
    }

    return $elements;
};
/** @return list<DOMElement> */
$elements = static fn(string $expression, ?DOMNode $context = null): array => $found($xpath->query(
    $expression,
    $context,
));
$metrics = $elements('/coverage/project/metrics')[0] ?? null;
if (!$metrics instanceof DOMElement) {
    fwrite(STDERR, "The Clover report at {$file} has no project metrics.\n");
    exit(2);
}
$failed = false;
foreach (['statements', 'methods'] as $metric) {
    $total = (int) $metrics->getAttribute($metric);
    $covered = (int) $metrics->getAttribute('covered' . $metric);
    printf("%s: %d/%d\n", $metric, $covered, $total);
    $failed = $failed || $covered < $total;
}
if (!$failed) {
    exit(0);
}
foreach ($elements('//file') as $source) {
    $missing = [];
    foreach ($elements('line[@type="stmt"][@count="0"]', $source) as $line) {
        $missing[] = $line->getAttribute('num');
    }
    if ([] !== $missing) {
        fwrite(STDERR, $source->getAttribute('name') . ': ' . implode(', ', $missing) . "\n");
    }
}
fwrite(STDERR, "Coverage is below 100%.\n");
exit(1);
