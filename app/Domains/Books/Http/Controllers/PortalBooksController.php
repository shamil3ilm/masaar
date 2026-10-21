<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Books: what an accountant needs to close a month and file the VAT return
 * from the portal - purchases and suppliers, the return drafted, what
 * customers owe and paid, and sales by any measure. This is its front page.
 */
class PortalBooksController extends Controller
{
    use ResolvesPortalOrganization;

    public function index(Request $request): View
    {
        $organizationId = $this->portalOrganizationId($request);

        return view('portal.books.index', [
            'records' => MemberRole::keepsBooks($request->user()?->roleIn($organizationId)),
        ]);
    }
}
