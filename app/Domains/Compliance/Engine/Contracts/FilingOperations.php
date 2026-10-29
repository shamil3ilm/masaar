<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\Enums\RequeueOutcome;

/**
 * The platform's own filing machinery, as the operator console runs it:
 * whether the authority answers, the circuit that stops calling it while it
 * does not, and a document waiting to be filed put back for another try.
 *
 * Platform-wide rather than an organization's, so it is not one of a
 * Jurisdiction's ports: the module whose authority the platform files with
 * live binds it (its provider), and the console's pages and health checks
 * name no country.
 */
interface FilingOperations
{
    /**
     * Whether the authority answers, from the last check when it is recent.
     *
     * @return array{available: bool, reason: ?string, latency_ms: ?int}
     */
    public function reachability(): array;

    /**
     * The same, asked again now.
     *
     * @return array{available: bool, reason: ?string, latency_ms: ?int}
     */
    public function recheck(): array;

    /**
     * Reachability with the circuit's state and the settings behind both,
     * for the console's connectivity panel.
     *
     * @return array<string, mixed>
     */
    public function detailedStatus(): array;

    /** Whether new documents are held for the drain instead of filed at once. */
    public function offline(): bool;

    /** Whether the circuit is open: the authority is not being called. */
    public function circuitOpen(): bool;

    /**
     * Close the circuit by hand, on the record as $by did it.
     *
     * @return array<string, mixed> the circuit as it now stands
     */
    public function resetCircuit(string $by): array;

    /** A failed document waiting to be filed, put back for the drain to try again. */
    public function requeue(string $itemId): RequeueOutcome;
}
