<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Controllers;

use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Http\SupportFileResponse;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Services\SupportDesk;
use App\Domains\Support\Services\TicketFiles;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Asking for help, from inside the portal.
 *
 * Everyone in the organization sees its tickets: whoever answers the next
 * reply is often not whoever opened it. A viewer reads them (org.writer).
 */
class PortalSupportController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly SupportDesk $desk,
    ) {}

    public function index(Request $request): View
    {
        return view('portal.support.index', [
            'tickets' => $this->desk->forOrganization((string) $this->organization($request)->id, self::PER_PAGE),
        ]);
    }

    public function create(): View
    {
        return view('portal.support.create', [
            'categories' => TicketCategory::cases(),
            'priorities' => TicketPriority::cases(),
            'maxFiles' => TicketFiles::MAX_FILES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'body' => ['required', 'string', 'max:10000'],
            'files' => ['array', 'max:'.TicketFiles::MAX_FILES],
            'files.*' => ['file'],
        ]);

        try {
            $ticket = $this->desk->open(
                $this->organization($request),
                $request->user(),
                TicketCategory::from($data['category']),
                TicketPriority::from($data['priority']),
                $data['subject'],
                $data['body'],
                $request->file('files', []),
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.support.show', $ticket->id)
            ->with('success', 'Ticket '.$ticket->reference().' is open. We reply here, and email you when we do.');
    }

    public function show(Request $request, string $ticketId): View
    {
        return view('portal.support.show', [
            'ticket' => $this->ticket($request, $ticketId),
            'maxFiles' => TicketFiles::MAX_FILES,
        ]);
    }

    public function reply(Request $request, string $ticketId): RedirectResponse
    {
        $ticket = $this->ticket($request, $ticketId);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'files' => ['array', 'max:'.TicketFiles::MAX_FILES],
            'files.*' => ['file'],
        ]);

        try {
            $this->desk->reply($ticket, $request->user(), $data['body'], $request->file('files', []));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.support.show', $ticket->id)->with('success', 'Reply sent.');
    }

    public function markResolved(Request $request, string $ticketId): RedirectResponse
    {
        $this->desk->markResolved($this->ticket($request, $ticketId));

        return redirect()->route('portal.support.show', $ticketId)->with('success', 'Marked as resolved. Reply if it comes back.');
    }

    public function attachment(Request $request, string $ticketId, string $attachmentId): BinaryFileResponse
    {
        $attachment = $this->desk->attachment($this->ticket($request, $ticketId), $attachmentId, staff: false) ?? abort(404);

        return SupportFileResponse::for($attachment, $this->desk->filePath($attachment));
    }

    private function ticket(Request $request, string $ticketId): SupportTicket
    {
        return $this->desk->findForOrganization((string) $this->organization($request)->id, $ticketId) ?? abort(404);
    }

    private function organization(Request $request): Organization
    {
        abort_unless(is_string($request->attributes->get(PortalTenant::ORG_ID)), 404);

        return $this->tenant->getOrganization() ?? abort(404);
    }
}
