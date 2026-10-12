<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http\Controllers;

use App\Domains\Webhook\Services\WebhookReport;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Customer webhook endpoints and their delivery log, for the platform team.
 *
 * Every attempt WebhookService made is already in webhook_logs, with the
 * status the receiver answered and the body it returned. Until this screen the
 * only way to read one was a psql prompt, so "our webhook stopped working" was
 * answered by guesswork - usually by asking the customer to check their own
 * logs.
 *
 * Read-only. The screen renders no delivery of its own: a page that posts to a
 * customer's live endpoint when someone refreshes it is a page nobody can use.
 *
 * Reachable only behind platform.admin, which checks users.is_platform_admin
 * and is deliberately not the per-organization admin role.
 */
class AdminWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookReport $webhooks,
    ) {}

    public function index(): View
    {
        return view('admin.webhooks.index', [
            'endpoints' => $this->webhooks->endpoints(),
        ]);
    }

    /**
     * One endpoint and its recent attempts.
     *
     * An unknown id raises ModelNotFoundException, which the web handler
     * renders as 404. Deliberate: an id that names no endpoint is a mistyped
     * URL, and an empty delivery table would read as an endpoint nothing has
     * ever been sent to.
     */
    public function show(string $webhookId): View
    {
        return view('admin.webhooks.show', [
            'endpoint' => $this->webhooks->endpoint($webhookId),
            'deliveries' => $this->webhooks->deliveries($webhookId),
        ]);
    }
}
