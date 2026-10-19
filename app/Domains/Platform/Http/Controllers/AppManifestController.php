<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest: what a phone calls the product when it is added to
 * the home screen, and the icons and colours it uses there.
 *
 * The name is config('app.name') - the product's name may change, and a
 * hard-coded one in a static file would outlive it. The icons are the
 * logo's mark (public/icons), with no words on them.
 */
class AppManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => 'rgb(251 250 247)',
            'theme_color' => 'rgb(26 31 54)',
            'icons' => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => asset('icons/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=86400'], JSON_UNESCAPED_SLASHES);
    }
}
