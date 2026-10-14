<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Services\Readiness;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The load balancer's question: send this instance traffic or not.
 *
 * Public, because a load balancer holds no credential. It names the dependency
 * that failed and nothing more - no hosts, no driver messages - since anyone
 * can ask it.
 */
class ReadinessController extends Controller
{
    public function __construct(
        private readonly Readiness $readiness,
    ) {}

    public function show(): JsonResponse
    {
        $checks = $this->readiness->check();
        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => array_map(fn (bool $ok): string => $ok ? 'ok' : 'failing', $checks),
        ], $ready ? 200 : 503);
    }
}
