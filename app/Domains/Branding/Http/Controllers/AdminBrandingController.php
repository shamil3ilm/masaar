<?php

declare(strict_types=1);

namespace App\Domains\Branding\Http\Controllers;

use Illuminate\Http\Request;

/**
 * The platform's own look, which every page starts from.
 *
 * Behind platform.admin on the route, like the rest of the console.
 */
class AdminBrandingController extends BrandingController
{
    protected function owner(Request $request): ?string
    {
        return null;
    }

    protected function view(): string
    {
        return 'admin.branding';
    }

    protected function route(): string
    {
        return 'admin.branding';
    }
}
