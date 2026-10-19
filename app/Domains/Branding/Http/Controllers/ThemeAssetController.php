<?php

declare(strict_types=1);

namespace App\Domains\Branding\Http\Controllers;

use App\Domains\Branding\Services\ThemeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The theme stylesheet, logo and favicon, for any page to load.
 *
 * Public: the sign-in page needs the platform's look before anyone has signed
 * in, and an organization's colours and logo are what it shows its customers
 * on every invoice anyway. Each URL carries the theme's version, so a
 * versioned request can be cached for good and a saved change is a new URL.
 *
 * An uploaded image is served with nosniff and, for an SVG, a sandboxing
 * content policy: opened directly, it is a document that can run nothing and
 * fetch nothing, whatever got past BrandAssets.
 */
class ThemeAssetController extends Controller
{
    public function __construct(private readonly ThemeService $themes) {}

    public function stylesheet(Request $request, string $scope): Response
    {
        return response($this->themes->stylesheet($scope), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => $this->caching($request),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * An invoice template's colour, for the print view: one variable, from
     * six hex digits the route has already checked, so nothing but a colour
     * reaches the stylesheet. The colour is the URL, so it never changes.
     */
    public function accent(string $accent): Response
    {
        return response(':root{--print-accent:#'.strtoupper($accent).';}', 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function logo(Request $request, string $scope): BinaryFileResponse
    {
        return $this->image($request, $scope, 'logo');
    }

    public function favicon(Request $request, string $scope): BinaryFileResponse
    {
        return $this->image($request, $scope, 'favicon');
    }

    /**
     * @param  'logo'|'favicon'  $kind
     */
    private function image(Request $request, string $scope, string $kind): BinaryFileResponse
    {
        $asset = $this->themes->asset($scope, $kind);

        abort_if($asset === null, 404);

        return response()->file($asset['path'], [
            'Content-Type' => $asset['type'],
            'Cache-Control' => $this->caching($request),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /**
     * A versioned URL never changes content, so it can be kept indefinitely;
     * an unversioned one is checked again shortly.
     */
    private function caching(Request $request): string
    {
        return $request->query('v') !== null ? 'public, max-age=31536000, immutable' : 'public, max-age=300';
    }
}
