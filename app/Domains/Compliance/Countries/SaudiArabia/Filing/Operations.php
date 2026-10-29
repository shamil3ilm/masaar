<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Compliance\Engine\Contracts\FilingOperations;
use App\Domains\Compliance\Engine\Enums\RequeueOutcome;

/**
 * Filing with ZATCA as the operator console runs it: the reachability check,
 * the circuit in front of the gateway, and the offline queue's retry.
 */
class Operations implements FilingOperations
{
    /** The circuit in front of ZATCA's gateway, as Connectivity names it. */
    private const SERVICE = 'zatca_api';

    public function __construct(
        private readonly Connectivity $connectivity,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly Requeuer $requeuer,
    ) {}

    public function reachability(): array
    {
        return $this->connectivity->check();
    }

    public function recheck(): array
    {
        return $this->connectivity->forceCheck();
    }

    public function detailedStatus(): array
    {
        return $this->connectivity->getDetailedStatus();
    }

    public function offline(): bool
    {
        return $this->connectivity->shouldUseOfflineMode();
    }

    public function circuitOpen(): bool
    {
        return $this->circuitBreaker->getState(self::SERVICE) === CircuitBreaker::STATE_OPEN;
    }

    public function resetCircuit(string $by): array
    {
        $result = $this->circuitBreaker->forceState(self::SERVICE, CircuitBreaker::STATE_CLOSED, 'Manual reset via admin dashboard', $by);

        $this->connectivity->resetCircuitBreaker();

        return $result;
    }

    public function requeue(string $itemId): RequeueOutcome
    {
        return $this->requeuer->requeue($itemId);
    }
}
