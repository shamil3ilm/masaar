<?php

declare(strict_types=1);

namespace App\Domains\Branding\Http;

use App\Domains\Branding\DTOs\ActiveTheme;
use App\Domains\Branding\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Hands the page's theme to every view that draws the frame of a page: the
 * layouts, the sign-in screens, the printed invoice and the logo.
 *
 * Resolved once per request and kept on it, since one page renders several of
 * these views and the answer is the same for all of them.
 */
final class ThemeComposer
{
    /**
     * The views that take the theme: page frames and the logo.
     */
    public const VIEWS = [
        'layouts.*',
        'auth.*',
        'welcome',
        'portal.select-org',
        'portal.invoices.print',
        'components.logo',
        'components.theme-head',
    ];

    /** Where the theme is kept on the request; the error frame sets it itself. */
    public const ATTRIBUTE = '_active_theme';

    /**
     * The theme service is resolved only when the request carries no theme.
     * A page that supplies its own - the error frame, drawn when the
     * database may be what failed - must not depend on the service being
     * constructible at all.
     */
    public function __construct(
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $theme = $this->request->attributes->get(self::ATTRIBUTE);

        if (! $theme instanceof ActiveTheme) {
            $theme = app(ThemeService::class)->forRequest($this->request);
            $this->request->attributes->set(self::ATTRIBUTE, $theme);
        }

        $view->with('theme', $theme);
    }
}
