<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Core\ModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleEnabled
{
    public function __construct(
        protected ModuleService $moduleService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $module = null): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Authentication required',
            ], 401);
        }

        // Determine module from parameter or route prefix
        $moduleCode = $module ?? $this->getModuleFromRoute($request);

        if (!$moduleCode) {
            // No module restriction, allow access
            return $next($request);
        }

        // Check if module is enabled for organization
        if (!$this->moduleService->isModuleEnabled($user->organization_id, $moduleCode)) {
            return response()->json([
                'error' => 'Module not enabled',
                'message' => "The '{$moduleCode}' module is not enabled for your organization.",
                'module' => $moduleCode,
                'upgrade_required' => true,
            ], 403);
        }

        // Check if user has access to module (if user-specific restrictions exist)
        if (!empty($user->module_access) && !in_array($moduleCode, $user->module_access)) {
            return response()->json([
                'error' => 'Access denied',
                'message' => "You do not have access to the '{$moduleCode}' module.",
                'module' => $moduleCode,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Extract module code from route prefix.
     */
    protected function getModuleFromRoute(Request $request): ?string
    {
        $routePrefixes = config('modules.route_prefixes', []);
        $path = $request->path();

        // Check each prefix mapping
        foreach ($routePrefixes as $prefix => $moduleCode) {
            // Match api/v1/{prefix} pattern
            if (preg_match("#^api/v\d+/{$prefix}(/|$)#", $path)) {
                return $moduleCode;
            }
        }

        return null;
    }
}
