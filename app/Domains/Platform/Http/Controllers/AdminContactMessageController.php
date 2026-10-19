<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Models\ContactMessage;
use App\Domains\Platform\Website\ContactHistory;
use App\Domains\Platform\Website\ContactInbox;
use App\Domains\Platform\Website\ContactStatus;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Website › Messages: what people sent from the home page's contact form.
 *
 * Answering is by email, from the admin's own mail (a mailto link), and then
 * marked replied here; nothing is sent from this screen. Every route takes
 * support.manage: answering people is the support role's work, and a sender
 * - often not a customer - is owed the same care with their details.
 */
class AdminContactMessageController extends Controller
{
    private const LIST = 'admin.website.messages.index';

    public function __construct(
        private readonly ContactInbox $inbox,
        private readonly ContactHistory $history,
        private readonly Lists $lists,
    ) {}

    public function index(Request $request): View
    {
        $list = $this->lists->admin($this->history->spec(self::LIST), $request);

        return view('admin.website.messages.index', [
            'messages' => $this->history->page($list),
            'list' => $list,
        ]);
    }

    public function show(Request $request, string $messageId): View
    {
        $message = $this->message($messageId);
        $this->inbox->markRead($message, (string) $request->user()?->getAuthIdentifier());

        return view('admin.website.messages.show', ['message' => $message]);
    }

    public function replied(Request $request, string $messageId): RedirectResponse
    {
        $message = $this->target($request, $messageId);
        $this->inbox->markReplied($message, (string) $request->user()?->getAuthIdentifier());

        return redirect()->route('admin.website.messages.show', $messageId)->with('success', __('Marked replied.'));
    }

    public function archive(Request $request, string $messageId): RedirectResponse
    {
        $this->inbox->archive($this->target($request, $messageId));

        return redirect()->route(self::LIST)->with('success', __('Message archived.'));
    }

    public function restore(Request $request, string $messageId): RedirectResponse
    {
        $message = $this->target($request, $messageId);

        if ($message->status !== ContactStatus::Archived) {
            return redirect()->route('admin.website.messages.show', $messageId);
        }

        $this->inbox->restore($message);

        return redirect()->route('admin.website.messages.show', $messageId)->with('success', __('Message restored.'));
    }

    private function target(Request $request, string $messageId): ContactMessage
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['ContactMessage', $messageId]);

        return $this->message($messageId);
    }

    private function message(string $messageId): ContactMessage
    {
        return $this->inbox->find($messageId) ?? abort(404);
    }
}
