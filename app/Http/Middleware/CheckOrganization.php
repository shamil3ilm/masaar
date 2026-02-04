<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Core\Branch;
use App\Models\Core\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Authentication required',
                ],
            ], 401);
        }

        // Super admins can access without organization
        if ($user->is_super_admin && !$user->organization_id) {
            return $next($request);
        }

        if (!$user->organization_id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NO_ORGANIZATION',
                    'message' => 'User is not associated with any organization',
                ],
            ], 403);
        }

        // Get and validate organization
        $organization = Organization::withoutGlobalScopes()->find($user->organization_id);

        if (!$organization) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ORGANIZATION_NOT_FOUND',
                    'message' => 'Organization not found',
                ],
            ], 403);
        }

        if (!$organization->is_active) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ORGANIZATION_INACTIVE',
                    'message' => 'Organization is inactive or suspended',
                ],
            ], 403);
        }

        // Store organization in request for easy access
        $request->attributes->set('organization', $organization);

        // Set branch context if provided in header
        $branchId = $request->header('X-Branch-Id');
        if ($branchId) {
            $branch = $user->branches()
                ->where('branches.id', $branchId)
                ->where('branches.is_active', true)
                ->first();

            if ($branch) {
                $request->attributes->set('branch', $branch);
            } else {
                // User doesn't have access to this branch
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'BRANCH_ACCESS_DENIED',
                        'message' => 'You do not have access to this branch',
                    ],
                ], 403);
            }
        } else {
            // Use default branch if no branch specified
            $defaultBranch = $user->getDefaultBranch();
            if ($defaultBranch) {
                $request->attributes->set('branch', $defaultBranch);
            }
        }

        return $next($request);
    }
}
