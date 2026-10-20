<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Controllers;

use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Support\Enums\RequestImportance;
use App\Domains\Support\Enums\TicketCategory;
use App\Domains\Support\Enums\TicketKind;
use App\Domains\Support\Enums\TicketPriority;
use App\Domains\Support\Http\SupportFileResponse;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\Services\RequestCategories;
use App\Domains\Support\Services\Requests;
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
 * The portal's Requests: asking for help, for a customization (paid work
 * for this company) or for a feature, from inside the portal.
 *
 * Everyone in the organization sees its requests: whoever answers the next
 * reply is often not whoever opened it. A viewer reads them (org.writer).
 */
class PortalSupportController extends Controller
{
    use ResolvesPortalOrganization;

    private const PER_PAGE = 20;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly SupportDesk $desk,
        private readonly Requests $requests,
        private readonly RequestCategories $categories,
    ) {}

    public function index(Request $request): View
    {
        return view('portal.support.index', [
            'tickets' => $this->desk->forOrganization((string) $this->organization($request)->id, self::PER_PAGE),
        ]);
    }

    /**
     * One form for the three kinds, chosen with ?kind=: help, a
     * customization or a feature request.
     */
    public function create(Request $request): View
    {
        return view('portal.support.create', [
            'kind' => TicketKind::tryFrom((string) $request->query('kind', old('kind', ''))) ?? TicketKind::Support,
            'kinds' => TicketKind::cases(),
            'categories' => TicketCategory::cases(),
            'priorities' => TicketPriority::cases(),
            'featureCategories' => $this->categories->offered(),
            'importances' => RequestImportance::cases(),
            'maxFiles' => TicketFiles::MAX_FILES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $kind = TicketKind::tryFrom((string) $request->input('kind', TicketKind::Support->value)) ?? abort(422);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:10000'],
            'files' => ['array', 'max:'.TicketFiles::MAX_FILES],
            'files.*' => ['file'],
            ...match ($kind) {
                TicketKind::Support => [
                    'category' => ['required', Rule::enum(TicketCategory::class)],
                    'priority' => ['required', Rule::enum(TicketPriority::class)],
                ],
                TicketKind::Feature => [
                    'feature_category' => ['required', Rule::in($this->categories->offeredKeys())],
                    'system_name' => ['nullable', 'string', 'max:120'],
                    'importance' => ['required', Rule::enum(RequestImportance::class)],
                ],
                TicketKind::Customization => [],
            },
        ]);

        $organization = $this->organization($request);
        $files = $request->file('files', []);

        try {
            $ticket = match ($kind) {
                TicketKind::Support => $this->desk->open($organization, $request->user(), TicketCategory::from($data['category']),
                    TicketPriority::from($data['priority']), $data['subject'], $data['body'], $files),
                TicketKind::Feature => $this->requests->openFeature($organization, $request->user(), $data['subject'], $data['body'],
                    $data['feature_category'], $data['system_name'] ?? null, RequestImportance::from($data['importance']), $files),
                TicketKind::Customization => $this->requests->openCustomization($organization, $request->user(), $data['subject'], $data['body'], $files),
            };
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.support.show', $ticket->id)
            ->with('success', $kind === TicketKind::Support
                ? __('Ticket :reference is open. We reply here, and email you when we do.', ['reference' => $ticket->reference()])
                : __('Request :reference is submitted. We reply here, and email you as it moves on.', ['reference' => $ticket->reference()]));
    }

    public function show(Request $request, string $ticketId): View
    {
        $ticket = $this->ticket($request, $ticketId);

        return view('portal.support.show', [
            'ticket' => $ticket,
            'categoryName' => $ticket->featureRequest !== null ? $this->categories->name((string) $ticket->featureRequest->category) : null,
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

        return redirect()->route('portal.support.show', $ticket->id)->with('success', __('Reply sent.'));
    }

    public function markResolved(Request $request, string $ticketId): RedirectResponse
    {
        $this->desk->markResolved($this->ticket($request, $ticketId));

        return redirect()->route('portal.support.show', $ticketId)->with('success', __('Marked as resolved. Reply if it comes back.'));
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
        return $this->portalOrganization($this->tenant, $request);
    }
}
