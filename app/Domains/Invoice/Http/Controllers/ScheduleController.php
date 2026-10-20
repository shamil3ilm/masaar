<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Http\Requests\CreateScheduleRequest;
use App\Domains\Invoice\Http\Requests\RepriceScheduleRequest;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Services\ScheduleLifecycle;
use App\Domains\Invoice\Services\SchedulePreview;
use App\Domains\Invoice\Services\Schedules;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Pagination\PageSize;
use App\Http\Responses\ApiResponse;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Schedules through the API: an organization's invoice to one buyer, made
 * again each period - drafted for review, or issued as it is made.
 *
 * GET  /api/schedules                 the organization's, newest first (?status=)
 * POST /api/schedules                 make one (CreateScheduleRequest)
 * GET  /api/schedules/{id}            one, with its runs and its next periods
 * POST /api/schedules/{id}/pause      stop it running, to resume later
 * POST /api/schedules/{id}/resume     carry on; periods that began meanwhile are skipped
 * POST /api/schedules/{id}/cancel     end it
 * POST /api/schedules/{id}/skip       leave its next period unbilled
 * PUT  /api/schedules/{id}/prices     new prices from its next period on
 * POST /api/schedules/{id}/runs/{run}/paid  an instalment of a plan delivered up front, paid
 * POST /api/schedules/{id}/runs/{run}/rerun a period whose draft was discarded, made again (administrators)
 *
 * A change the schedule's state does not allow answers 409 with why.
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Schedules $schedules,
        private readonly ScheduleLifecycle $lifecycle,
        private readonly SchedulePreview $preview,
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
        $source = $request->sourceInvoiceId();

        try {
            $schedule = $source === null
                ? $this->schedules->create($organization, $request->schedule(), $request->invoice())
                : $this->schedules->createFromInvoice($organization, $request->schedule(), $source);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::created($this->present((string) $schedule->id), 'Schedule created');
    }

    public function show(string $schedule): JsonResponse
    {
        return ApiResponse::success($this->present($schedule));
    }

    public function pause(string $schedule): JsonResponse
    {
        return $this->act($schedule, fn (InvoiceSchedule $found) => $this->lifecycle->pause($found), 'Schedule paused');
    }

    public function resume(string $schedule): JsonResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);

        return $this->act($schedule, fn (InvoiceSchedule $found) => $this->lifecycle->resume($found, $organization), 'Schedule resumed');
    }

    public function cancel(string $schedule): JsonResponse
    {
        return $this->act($schedule, fn (InvoiceSchedule $found) => $this->lifecycle->cancel($found), 'Schedule cancelled');
    }

    public function skip(string $schedule): JsonResponse
    {
        return $this->act($schedule, fn (InvoiceSchedule $found) => $this->lifecycle->skip($found), 'Next period skipped');
    }

    /** An instalment of a plan delivered up front, recorded paid. */
    public function paid(string $schedule, string $run): JsonResponse
    {
        return $this->act($schedule, fn (InvoiceSchedule $found) => $this->lifecycle->markPaid($found, $run), 'Instalment recorded paid');
    }

    /** A period whose draft was discarded, made again (an administrator's). */
    public function rerun(string $schedule, string $run): JsonResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);

        return $this->act($schedule, fn (InvoiceSchedule $found) => $this->lifecycle->rerun($found, $organization, $run), 'Period run again');
    }

    public function prices(RepriceScheduleRequest $request, string $schedule): JsonResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);

        return $this->act($schedule, fn (InvoiceSchedule $found) => $this->lifecycle->reprice($found, $organization, $request->prices()), 'Schedule prices changed');
    }

    /**
     * @param  Closure(InvoiceSchedule): InvoiceSchedule  $change
     */
    private function act(string $scheduleId, Closure $change, string $message): JsonResponse
    {
        try {
            $change($this->schedules->find((string) $this->tenant->getOrganizationId(), $scheduleId));
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::success($this->present($scheduleId), $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $scheduleId): array
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $schedule = $this->schedules->find((string) $organization->id, $scheduleId);

        return ['schedule' => $schedule, 'upcoming' => $this->preview->upcoming($schedule, $organization)];
    }
}
