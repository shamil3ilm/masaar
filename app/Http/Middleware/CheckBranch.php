<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBranch
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

        // Super admins bypass branch checks
        if ($user->is_super_admin) {
            return $next($request);
        }

        // Check if branch context is set
        $branch = $request->attributes->get('branch');

        if (!$branch) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NO_BRANCH',
                    'message' => 'Branch context is required. Please specify X-Branch-Id header.',
                ],
            ], 400);
        }

        return $next($request);
    }
}
