<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Core\ModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeatureEnabled
{
    public function __construct(
        protected ModuleService $moduleService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $module  Module code
     * @param  string  $feature  Feature code within the module
     */
    public function handle(Request $request, Closure $next, string $module, string $feature): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Authentication required',
            ], 401);
        }

        // Check if feature is enabled
        if (!$this->moduleService->isFeatureEnabled($user->organization_id, $module, $feature)) {
            // Check if module is enabled at all
            if (!$this->moduleService->isModuleEnabled($user->organization_id, $module)) {
                return response()->json([
                    'error' => 'Module not enabled',
                    'message' => "The '{$module}' module is not enabled for your organization.",
                    'module' => $module,
                    'feature' => $feature,
                    'upgrade_required' => true,
                ], 403);
            }

            return response()->json([
                'error' => 'Feature not enabled',
                'message' => "The '{$feature}' feature is not enabled for your organization.",
                'module' => $module,
                'feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
