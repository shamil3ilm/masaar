<?php

declare(strict_types=1);

/**
 * A stand-in tax authority for a load test that files (tills.php run
 * --file=1): every simplified invoice reported, after a delay like the real
 * gateway's. Never run in CI, never anywhere real.
 *
 *     LOAD_AUTHORITY_DELAY_MS=300 php -S 127.0.0.1:8190 scripts/load/authority.php
 *
 * and point the servers and workers at it with ZATCA_SANDBOX_URL. One PHP
 * built-in server answers one request at a time, so start several, one per
 * worker, when the delay is long.
 */
usleep(max(0, (int) getenv('LOAD_AUTHORITY_DELAY_MS')) * 1000);

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

header('Content-Type: application/json');

echo json_encode(match (true) {
    str_ends_with($path, '/invoices/reporting/single') => ['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []]],
    default => ['status' => 'ok'],
});
