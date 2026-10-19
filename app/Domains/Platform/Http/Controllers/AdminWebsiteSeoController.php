<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Website\WebsiteSeo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

/**
 * Website › Search and sharing: the public site's title and description in
 * search results, and the title, text and picture a shared link shows, in
 * English and Arabic (WebsiteSeo). Takes website.manage; a save is audited
 * with what was kept before and after.
 */
class AdminWebsiteSeoController extends Controller
{
    public function __construct(
        private readonly WebsiteSeo $seo,
    ) {}

    public function edit(): View
    {
        return view('admin.website.seo', [
            'definition' => $this->seo->definition(),
            'texts' => $this->seo->texts(),
            'image' => $this->seo->image(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['WebsiteSection', WebsiteSeo::KEY]);
        $image = $request->file('image');

        [$before, $after] = $this->seo->save(
            $request->except(['_token', 'image', 'remove_image']),
            $image instanceof UploadedFile ? $image : null,
            $request->boolean('remove_image'),
        );
        $request->attributes->set(AuditAdminAction::CHANGES, [$before, $after]);

        return redirect()->route('admin.website.seo.edit')->with('success', __('Search and sharing saved.'));
    }
}
