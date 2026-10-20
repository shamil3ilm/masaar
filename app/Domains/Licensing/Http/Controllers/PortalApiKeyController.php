<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Http\Controllers;

use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Services\ApiKeys;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Portal › Integrations: the organization's own API keys, made and revoked
 * by its administrators, with what each is used for and the plan's
 * allowances they share.
 *
 * A new key's secret is flashed to the next page and nowhere else: the row
 * keeps its peppered hash, so it is shown once and never again.
 */
class PortalApiKeyController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly ApiKeys $keys,
    ) {}

    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $organizationId = (string) $organization->id;

        return view('portal.integrations.keys', [
            'organization' => $organization,
            'keys' => $this->keys->list($organizationId),
            'scopes' => $this->keys->offeredScopes($organizationId),
            'refusal' => $this->keys->refusal($organization, $request->user()),
            'allowance' => $this->keys->allowance($organizationId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'environment' => ['required', Rule::in(array_column(LicenseEnvironment::cases(), 'value'))],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
            'approval_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $made = $this->keys->create($organization, $request->user(), $data, $data['approval_reason'] ?? null);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.integrations.index')
            ->with($made['waits'] ? 'warning' : 'success', $made['waits']
                ? __('API key created. It works once a platform administrator approves it.')
                : __('API key created.'))
            ->with('api_key_made', ['name' => $made['key']->name, 'key' => $made['key']->api_key, 'secret' => $made['secret']]);
    }

    public function revoke(Request $request, string $keyId): RedirectResponse
    {
        $organization = $this->organization($request);
        $key = $this->keys->find((string) $organization->id, $keyId) ?? abort(404);

        $this->keys->revoke($key);

        return redirect()->route('portal.integrations.index')->with('success', __('API key revoked.'));
    }

    private function organization(Request $request): Organization
    {
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can manage its integrations.'));
    }
}
