<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http\Controllers;

use App\Domains\Webhook\Services\WebhookReport;
use App\Domains\Webhook\Services\WebhookService;
use App\Http\Controllers\Controller;
use App\Support\OperatorError;
use Illuminate\Http\RedirectResponse;
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
 * Rendering is read-only. The screen sends nothing of its own: a page that
 * posts to a customer's live endpoint when someone refreshes it is a page
 * nobody can use. Resending is replay(), which is a POST behind a confirm for
 * that reason.
 *
 * Reachable only behind platform.admin, which checks users.is_platform_admin
 * and is deliberately not the per-organization admin role.
 */
class AdminWebhookController extends Controller
{
    public function __construct(
        private readonly WebhookReport $webhooks,
        private readonly WebhookService $deliveries,
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
            'deliveryTotal' => $this->webhooks->deliveryCount($webhookId),
            'deliveryCap' => $this->webhooks->deliveryCap(),
        ]);
    }

    /**
     * Send a logged delivery to the endpoint again.
     *
     * The log row already holds the event and the payload and deliver() is
     * public, so this needs no delivery machinery of its own - which also means
     * the replay records itself as another webhook_logs row, updates the
     * endpoint's failure count, and is visible on the page it redirects back
     * to, exactly like the delivery it repeats.
     *
     * Three things this is careful about, because unlike everything else in the
     * console it makes a real request to a customer's live server:
     *
     *   The lookups sit outside the try. An id that names no endpoint, or a log
     *   that belongs to a different one, has to stay a 404 - catching it here
     *   would turn a refusal into a friendly message and lose the only signal
     *   that the pairing was wrong.
     *
     *   deliver() answers false for a status the receiver did not accept, and
     *   swallows the connection failure itself, so the boolean is the usual
     *   failure path - not the catch below.
     *
     *   \Throwable is still caught, because an endpoint nobody can reach must
     *   not be a 500 page: the admin needs to be told the customer's server is
     *   down, which is not the same as the console being broken.
     */
    public function replay(string $webhookId, string $logId): RedirectResponse
    {
        $endpoint = $this->webhooks->endpoint($webhookId);
        $delivery = $this->webhooks->delivery($webhookId, $logId);

        // WebhookService::deliver() refuses a disabled endpoint on its own,
        // and would return false - which this method reports as "the endpoint
        // did not accept it". That reads as the receiver answering, which it
        // did not. Checked here so the reason given is the real one.
        if (! $endpoint->is_active) {
            return $this->backTo($webhookId)->with(
                'error',
                'This endpoint is disabled, so nothing was sent. Either the customer '
                .'turned it off or ten consecutive failures turned it off for them. '
                .'Re-enable it before replaying.'
            );
        }

        try {
            $accepted = $this->deliveries->deliver($endpoint, $delivery->event, $delivery->sentPayload());
        } catch (\Throwable $e) {
            return $this->backTo($webhookId)->with('error', OperatorError::message($e, 'Replaying the delivery'));
        }

        if (! $accepted) {
            return $this->backTo($webhookId)->with(
                'error',
                'Replayed, and the endpoint did not accept it. The attempt is the newest row below, with the status and body it answered.'
            );
        }

        return $this->backTo($webhookId)->with(
            'success',
            'Replayed, and the endpoint accepted it. The receiver may process this event a second time - it has now been delivered twice.'
        );
    }

    private function backTo(string $webhookId): RedirectResponse
    {
        return redirect()->route('admin.webhooks.show', $webhookId);
    }
}
