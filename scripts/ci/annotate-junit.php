<?php

/*
 * Print each failed test in a JUnit report as a workflow annotation.
 *
 * The job log needs a signed-in reader; annotations do not. A suite that
 * fails in CI and passes locally can then be read from the run's summary.
 *
 * Usage: php scripts/ci/annotate-junit.php junit.xml
 */

declare(strict_types=1);

$path = $argv[1] ?? '';

if ($path === '' || ! is_file($path)) {
    fwrite(STDOUT, "::error title=No JUnit report::{$path} was not written; the suite may not have started.\n");
    exit(0);
}

$xml = simplexml_load_file($path);

if ($xml === false) {
    exit(0);
}

$shown = 0;

foreach ($xml->xpath('//testcase[failure or error]') as $case) {
    $node = $case->failure ?: $case->error;
    $text = trim((string) $node);
    // The first meaningful lines: the assertion and where it failed.
    $lines = array_slice(array_values(array_filter(explode("\n", $text), fn (string $l): bool => trim($l) !== '')), 0, 6);
    $message = str_replace(["\r", "\n"], ['', '%0A'], implode("\n", $lines));

    fwrite(STDOUT, '::error title='.$case['class'].'::'.$case['name'].': '.substr($message, 0, 1500)."\n");

    // GitHub keeps only the first fifty annotations of a step.
    if (++$shown >= 40) {
        break;
    }
}
