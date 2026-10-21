<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Rules\PublicEndpoint;
use App\Domains\Webhook\Services\DeliveryHistory;
use App\Domains\Webhook\Services\Subscriptions;
use App\Domains\Webhook\Services\WebhookEvents;
use App\Domains\Webhook\Services\WebhookService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Portal › Integrations › Webhooks: where the organization's systems are
 * told what happened to its invoices.
 *
 * Its administrators add, change and remove endpoints, choose the events,
 * send a test, and read each endpoint's recent deliveries with their status
 * codes and retries - payloads with personal data taken out
 * (DeliveryHistory). The signing secret is shown once, when the endpoint is
 * added or its secret rotated; receivers verify deliveries with it.
 *
 * Adding one asks Access for webhooks: the plan, the country and the role.
 * Endpoints already there can always be read, changed and removed.
 */
class PortalWebhookController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Subscriptions $subscriptions,
        private readonly WebhookService $webhooks,
        private readonly DeliveryHistory $history,
        private readonly Access $access,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $decision = $this->access->decide($organization, $request->user(), Feature::Webhooks);

        return view('portal.integrations.webhooks', [
            'organization' => $organization,
            'endpoints' => $this->subscriptions->list((string) $organization->id),
            'events' => WebhookEvents::labels($this->jurisdictions->forRules($organization)->presentation()),
            'refusal' => $decision->allowed ? null : $decision->reason,
        ]);
    }

    public function show(Request $request, string $webhookId): View
    {
        $organization = $this->organization($request);
        $endpoint = $this->endpoint($organization, $webhookId);

        return view('portal.integrations.webhook', [
            'organization' => $organization,
            'endpoint' => $endpoint,
            'events' => WebhookEvents::labels($this->jurisdictions->forRules($organization)->presentation()),
            'deliveries' => $this->history->recent($endpoint),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $decision = $this->access->decide($organization, $request->user(), Feature::Webhooks);

        if (! $decision->allowed) {
            return back()->withInput()->with('error', $decision->reason);
        }

        $data = $request->validate($this->rules());
        $endpoint = $this->subscriptions->create((string) $organization->id, $data['url'], array_values($data['events']));

        return redirect()->route('portal.integrations.webhooks.show', $endpoint->id)
            ->with('success', __('Webhook added.'))
            ->with('webhook_secret', $endpoint->secret);
    }

    public function update(Request $request, string $webhookId): RedirectResponse
    {
        $endpoint = $this->endpoint($this->organization($request), $webhookId);
        $data = $request->validate($this->rules());

        $this->subscriptions->update($endpoint, [
            'url' => $data['url'],
            'events' => array_values($data['events']),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('portal.integrations.webhooks.show', $endpoint->id)->with('success', __('Webhook updated.'));
    }

    public function destroy(Request $request, string $webhookId): RedirectResponse
    {
        $this->subscriptions->delete($this->endpoint($this->organization($request), $webhookId));

        return redirect()->route('portal.integrations.webhooks.index')->with('success', __('Webhook removed.'));
    }

    public function rotate(Request $request, string $webhookId): RedirectResponse
    {
        $endpoint = $this->endpoint($this->organization($request), $webhookId);

        return redirect()->route('portal.integrations.webhooks.show', $endpoint->id)
            ->with('success', __('Signing secret rotated. Deliveries are signed with the new one from now on.'))
            ->with('webhook_secret', $this->subscriptions->rotateSecret($endpoint));
    }

    /**
     * One test event, sent now, and its answer said.
     */
    public function test(Request $request, string $webhookId): RedirectResponse
    {
        $endpoint = $this->endpoint($this->organization($request), $webhookId);

        if (! $endpoint->is_active) {
            return back()->with('error', __('This webhook is switched off. Switch it on to send a test.'));
        }

        return back()->with(...($this->webhooks->test($endpoint)
            ? ['success', __('Test event delivered.')]
            : ['error', __('The test event was not delivered. Its answer is under Recent deliveries.')]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            // Any scheme passes 'url', so an http address is refused once,
            // by PublicEndpoint, for the reason it really is.
            'url' => ['required', 'url', 'max:500', new PublicEndpoint],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in([...WebhookService::EVENTS, '*'])],
        ];
    }

    private function endpoint(Organization $organization, string $webhookId): Webhook
    {
        try {
            return $this->subscriptions->find((string) $organization->id, $webhookId);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function organization(Request $request): Organization
    {
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can manage its integrations.'));
    }
}
