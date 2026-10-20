<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Controllers;

use App\Domains\Billing\Enums\BillingCurrency;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Support\Enums\RequestStage;
use App\Domains\Support\Enums\TicketKind;
use App\Domains\Support\Enums\TicketStatus;
use App\Domains\Support\Http\SupportFileResponse;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Services\FeatureBoard;
use App\Domains\Support\Services\PaidCustomizations;
use App\Domains\Support\Services\RequestCategories;
use App\Domains\Support\Services\Requests;
use App\Domains\Support\Services\SupportDesk;
use App\Domains\Support\Services\TicketFiles;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The support queue: every organization's tickets - help, customizations and
 * feature requests - for the admins who answer them.
 */
class AdminSupportController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly SupportDesk $desk,
        private readonly Requests $requests,
        private readonly PaidCustomizations $customizations,
        private readonly RequestCategories $categories,
        private readonly FeatureBoard $board,
    ) {}

    public function index(Request $request): View
    {
        // Open by default: the queue is what is waiting on support. "all"
        // shows everything.
        $status = TicketStatus::tryFrom((string) $request->query('status', TicketStatus::Open->value));
        $assigned = in_array($request->query('assigned'), ['mine', 'unassigned'], true) ? (string) $request->query('assigned') : null;
        $search = trim((string) $request->query('q', ''));
        $kind = TicketKind::tryFrom((string) $request->query('kind', ''));

        return view('admin.support.index', [
            'tickets' => $this->desk->queue($status, $assigned, (string) Auth::id(), $search === '' ? null : $search, self::PER_PAGE, $kind)->withQueryString(),
            'status' => $status,
            'assigned' => $assigned,
            'search' => $search,
            'kind' => $kind,
            'statuses' => TicketStatus::cases(),
            'kinds' => TicketKind::cases(),
        ]);
    }

    public function show(string $ticketId): View
    {
        $ticket = $this->ticket($ticketId);

        return view('admin.support.show', [
            'ticket' => $ticket,
            'assignees' => $this->desk->assignees(),
            'statuses' => TicketStatus::cases(),
            'stages' => RequestStage::cases(),
            'categoryName' => $ticket->featureRequest !== null ? $this->categories->name((string) $ticket->featureRequest->category) : null,
            'categories' => $this->categories->all(),
            // A feature request's place on the board, or the items it may join.
            'boardItem' => $ticket->featureRequest?->boardItem,
            'boardItems' => $ticket->kind === TicketKind::Feature ? $this->board->forStaff(null, FeatureBoard::SORT_NEWEST) : collect(),
            'features' => Feature::cases(),
            'currencies' => BillingCurrency::codes(),
            'maxFiles' => TicketFiles::MAX_FILES,
        ]);
    }

    /**
     * A customization or a feature request moved to another stage, with a
     * note the customer reads.
     */
    public function stage(Request $request, string $ticketId): RedirectResponse
    {
        $ticket = $this->ticket($ticketId);
        $request->attributes->set(AuditAdminAction::TARGET, ['SupportTicket', $ticketId]);

        $data = $request->validate([
            'stage' => ['required', Rule::enum(RequestStage::class)],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->requests->moveStage($ticket, $request->user(), RequestStage::from($data['stage']), $data['note'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.support.show', $ticketId)->with('success', 'Stage changed. The customer is emailed.');
    }

    /**
     * What was agreed for a customization: the work, the price, whether it
     * is billed, and the features it switches on once released.
     */
    public function customization(Request $request, string $ticketId): RedirectResponse
    {
        $ticket = $this->ticket($ticketId);
        $request->attributes->set(AuditAdminAction::TARGET, ['SupportTicket', $ticketId]);
        $customization = $ticket->customization ?? abort(404);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:5000'],
            'billable' => ['required', 'boolean'],
            'price' => ['nullable', 'required_if:billable,1', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'currency' => ['nullable', 'required_with:price', Rule::in(BillingCurrency::codes())],
            'features' => ['array'],
            'features.*' => [Rule::enum(Feature::class)],
        ]);

        try {
            $this->customizations->agree($customization, $data['description'] ?? null, isset($data['price']) ? (string) $data['price'] : null,
                $data['currency'] ?? null, (bool) $data['billable'], array_values((array) ($data['features'] ?? [])), (string) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.support.show', $ticketId)->with('success', 'Customization saved.');
    }

    public function reply(Request $request, string $ticketId): RedirectResponse
    {
        $ticket = $this->ticket($ticketId);
        $request->attributes->set(AuditAdminAction::TARGET, ['SupportTicket', $ticketId]);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'internal' => ['sometimes', 'boolean'],
            'files' => ['array', 'max:'.TicketFiles::MAX_FILES],
            'files.*' => ['file'],
        ]);

        $internal = (bool) ($data['internal'] ?? false);

        try {
            $this->desk->reply($ticket, $request->user(), $data['body'], $request->file('files', []), staff: true, internal: $internal);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.support.show', $ticketId)
            ->with('success', $internal ? 'Note added. The customer does not see it.' : 'Reply sent. The customer is emailed.');
    }

    public function assign(Request $request, string $ticketId): RedirectResponse
    {
        $ticket = $this->ticket($ticketId);
        $request->attributes->set(AuditAdminAction::TARGET, ['SupportTicket', $ticketId]);

        $assignee = $request->input('assigned_to');

        try {
            $this->desk->assign($ticket, is_string($assignee) && $assignee !== '' ? $assignee : null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.support.show', $ticketId)->with('success', 'Assignment saved.');
    }

    public function status(Request $request, string $ticketId): RedirectResponse
    {
        $ticket = $this->ticket($ticketId);
        $request->attributes->set(AuditAdminAction::TARGET, ['SupportTicket', $ticketId]);

        $status = TicketStatus::from($request->validate(['status' => ['required', Rule::enum(TicketStatus::class)]])['status']);

        $this->desk->moveTo($ticket, $status);

        return redirect()->route('admin.support.show', $ticketId)->with('success', 'Status changed to '.$status->label().'.');
    }

    public function attachment(string $ticketId, string $attachmentId): BinaryFileResponse
    {
        $attachment = $this->desk->attachment($this->ticket($ticketId), $attachmentId, staff: true) ?? abort(404);

        return SupportFileResponse::for($attachment, $this->desk->filePath($attachment));
    }

    private function ticket(string $ticketId): SupportTicket
    {
        return $this->desk->findForStaff($ticketId) ?? abort(404);
    }
}
