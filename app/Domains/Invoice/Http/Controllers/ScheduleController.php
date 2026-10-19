<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Http\Requests\CreateScheduleRequest;
use App\Domains\Invoice\Services\Schedules;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Pagination\PageSize;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Schedules through the API: an organization's invoice to one buyer, made
 * again each period - drafted for review, or issued as it is made.
 *
 * GET  /api/schedules        the organization's, newest first (?status=)
 * POST /api/schedules        make one (CreateScheduleRequest)
 * GET  /api/schedules/{id}   one, with its runs and its next periods
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Schedules $schedules,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return ApiResponse::paginated($this->schedules->paginate(
            (string) $this->tenant->getOrganizationId(),
            is_string($status) && $status !== '' ? $status : null,
            PageSize::fromRequest($request, 15),
        ));
    }

    public function store(CreateScheduleRequest $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $schedule = $this->schedules->create($organization, $request->schedule(), $request->invoice());

        return ApiResponse::created($this->present((string) $organization->id, (string) $schedule->id), 'Schedule created');
    }

    public function show(string $schedule): JsonResponse
    {
        return ApiResponse::success($this->present((string) $this->tenant->getOrganizationId(), $schedule));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $organizationId, string $scheduleId): array
    {
        $schedule = $this->schedules->find($organizationId, $scheduleId);

        return ['schedule' => $schedule, 'upcoming' => $this->schedules->upcoming($schedule)];
    }
}
