<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Http\Requests\CreatePortalScheduleRequest;
use App\Domains\Invoice\Http\Requests\RepriceScheduleRequest;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Services\ScheduleHistory;
use App\Domains\Invoice\Services\ScheduleLifecycle;
use App\Domains\Invoice\Services\SchedulePreview;
use App\Domains\Invoice\Services\Schedules;
use App\Domains\Invoice\Services\ScheduleSources;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Support\CountryClock;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The portal's Schedules: the list, a schedule's page with its next runs
 * and its past ones, the form that repeats an invoice on a schedule, and
 * pausing, resuming, cancelling, skipping and repricing.
 *
 * A schedule is looked up within the organization in the query (Schedules),
 * so another organization's is not found.
 */
class PortalScheduleController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Schedules $schedules,
        private readonly ScheduleSources $sources,
        private readonly ScheduleLifecycle $lifecycle,
        private readonly SchedulePreview $preview,
        private readonly ScheduleHistory $history,
        private readonly Lists $lists,
    ) {}

    public function index(Request $request): View
    {
        $organizationId = $this->portalOrganizationId($request);
        $list = $this->lists->portal($this->history->spec('portal.schedules.index'), $request, $organizationId);

        return view('portal.schedules.index', [
            'schedules' => $this->history->page($organizationId, $list),
            'list' => $list,
        ]);
    }

    /**
     * The form: for the invoice it repeats (?invoice=, from the invoice's
     * page), or with the organization's latest invoices to choose from.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $invoiceId = $request->query('invoice');
        $source = null;

        if (is_string($invoiceId) && $invoiceId !== '') {
            try {
                $source = $this->sources->fromInvoice($organization, $invoiceId);
            } catch (ModelNotFoundException) {
                abort(404);
            } catch (DomainException $e) {
                return redirect()->route('portal.invoices.show', $invoiceId)->with('error', $e->getMessage());
            }
        }

        return view('portal.schedules.form', [
            'invoice' => $source['invoice'] ?? null,
            'choices' => $source === null ? $this->sources->choices((string) $organization->id) : collect(),
            'dueDays' => $source['attributes']['due_days'] ?? null,
            'frequencies' => ScheduleFrequency::options(),
            'frequency' => $this->schedules->defaultFrequency($organization, $request->user()?->id),
            'prepaymentOffered' => $this->schedules->offersPrepayments($organization),
            'today' => CountryClock::now($organization->country)->toDateString(),
        ]);
    }

    public function store(CreatePortalScheduleRequest $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);

        try {
            $schedule = $this->schedules->createFromInvoice($organization, $request->schedule(), (string) $request->sourceInvoiceId());
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.schedules.show', $schedule->id)->with('success', __('Schedule saved. Its first invoice is made on :date.', ['date' => $schedule->next_run_on?->toDateString()]));
    }

    public function show(Request $request, string $scheduleId): View
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $schedule = $this->find((string) $organization->id, $scheduleId);

        return view('portal.schedules.show', [
            'schedule' => $schedule,
            'upcoming' => $this->preview->upcoming($schedule, $organization),
            'instalments' => $schedule->instalment_model !== null,
        ]);
    }

    /** Pause, resume, cancel or skip the next period. */
    public function act(Request $request, string $scheduleId, string $action): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $schedule = $this->find((string) $organization->id, $scheduleId);

        try {
            match ($action) {
                'pause' => $this->lifecycle->pause($schedule),
                'resume' => $this->lifecycle->resume($schedule, $organization),
                'cancel' => $this->lifecycle->cancel($schedule),
                'skip' => $this->lifecycle->skip($schedule),
            };
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.schedules.show', $scheduleId)->with('success', match ($action) {
            'pause' => __('Schedule paused. No invoice is made until it is resumed.'),
            'resume' => __('Schedule resumed. Periods that began while it was paused are not billed.'),
            'cancel' => __('Schedule cancelled. Invoices already made are not affected.'),
            default => __('The next period was skipped.'),
        });
    }

    /** An instalment of a plan delivered up front, recorded paid. */
    public function paid(Request $request, string $scheduleId, string $runId): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $schedule = $this->find((string) $organization->id, $scheduleId);

        try {
            $this->lifecycle->markPaid($schedule, $runId);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.schedules.show', $scheduleId)->with('success', __('Instalment recorded paid.'));
    }

    public function prices(RepriceScheduleRequest $request, string $scheduleId): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $schedule = $this->find((string) $organization->id, $scheduleId);

        try {
            $this->lifecycle->reprice($schedule, $organization, $request->prices());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.schedules.show', $scheduleId)->with('success', __('Prices changed from the next period on. Invoices already made keep theirs.'));
    }

    private function find(string $organizationId, string $scheduleId): InvoiceSchedule
    {
        try {
            return $this->schedules->findWithInvoices($organizationId, $scheduleId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }
}
