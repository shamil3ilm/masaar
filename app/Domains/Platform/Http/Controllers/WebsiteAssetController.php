<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Website\Testimonials;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The public website's uploaded images, for anyone to load.
 *
 * Only what the page shows: a published testimonial's logo. Each is served
 * as the type it was checked to be (WebsiteImages), with nosniff and a
 * sandboxing content policy, so opened directly it can run nothing and
 * fetch nothing. A versioned URL is cached for good; a saved change is a
 * new URL.
 */
class WebsiteAssetController extends Controller
{
    public function __construct(
        private readonly Testimonials $testimonials,
    ) {}

    public function testimonialLogo(Request $request, string $testimonialId): BinaryFileResponse
    {
        $testimonial = $this->testimonials->find($testimonialId);
        $logo = $testimonial !== null && $testimonial->published ? $this->testimonials->logo($testimonial) : null;

        abort_if($logo === null, 404);

        return $this->image($request, $logo);
    }

    /**
     * @param  array{path: string, type: string}  $image
     */
    private function image(Request $request, array $image): BinaryFileResponse
    {
        return response()->file($image['path'], [
            'Content-Type' => $image['type'],
            'Cache-Control' => $request->query('v') !== null ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
