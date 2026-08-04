<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\ApiKey;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API Key management controller.
 *
 * Allows organizations to create and manage API keys
 * for server-to-server integration.
 */
class ApiKeyController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly AuditService $audit,
    ) {}

    /**
     * List API keys for current organization.
     *
     * GET /api/api-keys
     */
    public function index(): JsonResponse
    {
        $keys = ApiKey::where('organization_id', $this->tenant->getOrganizationId())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'key_prefix' => $key->key_prefix,
                'scopes' => $key->scopes,
                'is_active' => $key->is_active,
                'last_used_at' => $key->last_used_at?->toISOString(),
                'expires_at' => $key->expires_at?->toISOString(),
                'created_at' => $key->created_at->toISOString(),
            ]);

        return ApiResponse::success(['api_keys' => $keys]);
    }

    /**
     * Create a new API key.
     *
     * POST /api/api-keys
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'in:*,invoices:read,invoices:write,compliance:read,compliance:write,webhooks:manage'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $result = ApiKey::generate(
            $this->tenant->getOrganizationId(),
            $request->name,
            $request->scopes ?? ['*'],
            $request->expires_at ? new \DateTime($request->expires_at) : null
        );

        // The key itself is never audited, only that one was issued and by whom.
        $this->audit->logSecurity('api_key.created', 'ApiKey', $result['model']->id, [
            'name' => $result['model']->name,
            'scopes' => $result['model']->scopes,
            'expires_at' => $result['model']->expires_at?->toISOString(),
        ]);

        return ApiResponse::created([
            'api_key' => [
                'id' => $result['model']->id,
                'name' => $result['model']->name,
                'key' => $result['plain_key'], // Only shown once at creation
                'scopes' => $result['model']->scopes,
                'expires_at' => $result['model']->expires_at?->toISOString(),
            ],
            'message' => 'API key created. Save the key - it will not be shown again.',
        ]);
    }

    /**
     * Get API key details.
     *
     * GET /api/api-keys/{id}
     */
    public function show(string $id): JsonResponse
    {
        $key = $this->getApiKey($id);

        return ApiResponse::success([
            'api_key' => [
                'id' => $key->id,
                'name' => $key->name,
                'key_prefix' => $key->key_prefix,
                'scopes' => $key->scopes,
                'is_active' => $key->is_active,
                'last_used_at' => $key->last_used_at?->toISOString(),
                'expires_at' => $key->expires_at?->toISOString(),
                'created_at' => $key->created_at->toISOString(),
            ],
        ]);
    }

    /**
     * Update API key.
     *
     * PUT /api/api-keys/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $key = $this->getApiKey($id);

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'in:*,invoices:read,invoices:write,compliance:read,compliance:write,webhooks:manage'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $key->update($request->only(['name', 'scopes', 'is_active']));

        return ApiResponse::success([
            'api_key' => [
                'id' => $key->id,
                'name' => $key->name,
                'scopes' => $key->scopes,
                'is_active' => $key->is_active,
            ],
        ], 'API key updated');
    }

    /**
     * Delete (revoke) API key.
     *
     * DELETE /api/api-keys/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $key = $this->getApiKey($id);

        $this->audit->logSecurity('api_key.revoked', 'ApiKey', $key->id, [
            'name' => $key->name,
        ]);

        $key->delete();

        return ApiResponse::success(null, 'API key revoked');
    }

    /**
     * Get available scopes.
     *
     * GET /api/api-keys/scopes
     */
    public function scopes(): JsonResponse
    {
        return ApiResponse::success([
            'scopes' => [
                '*' => 'Full access to all endpoints',
                'invoices:read' => 'Read invoice data',
                'invoices:write' => 'Create and update invoices',
                'compliance:read' => 'Read compliance status',
                'compliance:write' => 'Submit invoices to ZATCA',
                'webhooks:manage' => 'Manage webhook subscriptions',
            ],
        ]);
    }

    /**
     * Get API key scoped to current organization.
     */
    private function getApiKey(string $id): ApiKey
    {
        return ApiKey::where('organization_id', $this->tenant->getOrganizationId())
            ->findOrFail($id);
    }
}
