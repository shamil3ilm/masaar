<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Controllers;

use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Support\Enums\TicketStatus;
use App\Domains\Support\Http\SupportFileResponse;
use App\Domains\Support\Models\SupportTicket;
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
 * The support queue: every organization's tickets, for the admins who answer
 * them.
 */
class AdminSupportController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly SupportDesk $desk,
    ) {}

    public function index(Request $request): View
    {
        // Open by default: the queue is what is waiting on support. "all"
        // shows everything.
        $status = TicketStatus::tryFrom((string) $request->query('status', TicketStatus::Open->value));
        $assigned = in_array($request->query('assigned'), ['mine', 'unassigned'], true) ? (string) $request->query('assigned') : null;
        $search = trim((string) $request->query('q', ''));

        return view('admin.support.index', [
            'tickets' => $this->desk->queue($status, $assigned, (string) Auth::id(), $search === '' ? null : $search, self::PER_PAGE)->withQueryString(),
            'status' => $status,
            'assigned' => $assigned,
            'search' => $search,
            'statuses' => TicketStatus::cases(),
        ]);
    }

    public function show(string $ticketId): View
    {
        return view('admin.support.show', [
            'ticket' => $this->ticket($ticketId),
            'assignees' => $this->desk->assignees(),
            'statuses' => TicketStatus::cases(),
            'maxFiles' => TicketFiles::MAX_FILES,
        ]);
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
