<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Middleware;

use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Access\Access;
use App\Domains\Platform\Access\Feature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every Books route asks Access for the books feature - the country has
 * it, the organization is not switched off, the person is a member - and
 * a change asks for a role that keeps the books (MemberRole::BOOKKEEPING):
 * an accountant records here though it changes nothing elsewhere, and a
 * viewer only reads.
 *
 * In place of org.writer, which would refuse the accountant.
 */
class BooksAccess
{
    private const READS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Access $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);
        $decision = $this->access->decide($organization, $request->user(), Feature::Books);

        abort_unless($decision->allowed, 403, $decision->reason);

        if (! in_array($request->method(), self::READS, true)
            && ! MemberRole::keepsBooks($request->user()?->roleIn((string) $organization->id))) {
            abort(403, __('Your role in this organization only reads the books.'));
        }

        return $next($request);
    }
}
