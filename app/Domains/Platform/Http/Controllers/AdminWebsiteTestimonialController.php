<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Models\WebsiteTestimonial;
use App\Domains\Platform\Website\Testimonials;
use App\Domains\Platform\Website\WebsiteSections;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

/**
 * Website › Testimonials: what customers said, written in both languages,
 * ordered and published here. The home page shows published ones while its
 * testimonials section is on (off by default, Website › Home page).
 *
 * Every route takes website.manage, and every change is on the audit record
 * (AuditAdminAction) with the testimonial it changed and its text before
 * and after.
 */
class AdminWebsiteTestimonialController extends Controller
{
    private const LIST = 'admin.website.testimonials.index';

    /** What the audit record keeps of one, before and after a change. */
    private const AUDITED = ['quote_en', 'quote_ar', 'name_en', 'name_ar', 'role_en', 'role_ar', 'company_en', 'company_ar', 'published', 'position', 'logo_path'];

    public function __construct(
        private readonly Testimonials $testimonials,
        private readonly WebsiteSections $sections,
    ) {}

    public function index(): View
    {
        return view('admin.website.testimonials.index', [
            'testimonials' => $this->testimonials->all(),
            'sectionOn' => $this->sections->switchedOn('testimonials'),
        ]);
    }

    public function create(): View
    {
        return view('admin.website.testimonials.form', ['testimonial' => new WebsiteTestimonial]);
    }

    public function store(Request $request): RedirectResponse
    {
        $testimonial = $this->testimonials->create($request->all(), $this->logo($request));
        $this->audit($request, $testimonial->id, null, $testimonial);

        return redirect()->route(self::LIST)->with('success', __('Testimonial added.'));
    }

    public function edit(string $testimonialId): View
    {
        return view('admin.website.testimonials.form', ['testimonial' => $this->find($testimonialId)]);
    }

    public function update(Request $request, string $testimonialId): RedirectResponse
    {
        $testimonial = $this->find($testimonialId);
        $before = $testimonial->only(self::AUDITED);
        $this->testimonials->update($testimonial, $request->all(), $this->logo($request), $request->boolean('remove_logo'));
        $this->audit($request, $testimonialId, $before, $testimonial);

        return redirect()->route(self::LIST)->with('success', __('Testimonial saved.'));
    }

    public function visibility(Request $request, string $testimonialId): RedirectResponse
    {
        $testimonial = $this->find($testimonialId);
        $before = $testimonial->only(self::AUDITED);
        $this->testimonials->setPublished($testimonial, $request->boolean('published'));
        $this->audit($request, $testimonialId, $before, $testimonial);

        return redirect()->route(self::LIST)->with('success', $testimonial->published ? __('Testimonial published.') : __('Testimonial hidden.'));
    }

    public function move(Request $request, string $testimonialId): RedirectResponse
    {
        $testimonial = $this->find($testimonialId);
        $request->attributes->set(AuditAdminAction::TARGET, ['WebsiteTestimonial', $testimonialId]);
        $this->testimonials->move($testimonial, $request->input('direction') === 'up' ? 'up' : 'down');

        return redirect()->route(self::LIST);
    }

    public function destroy(Request $request, string $testimonialId): RedirectResponse
    {
        $testimonial = $this->find($testimonialId);
        $before = $testimonial->only(self::AUDITED);
        $this->testimonials->delete($testimonial);
        $request->attributes->set(AuditAdminAction::TARGET, ['WebsiteTestimonial', $testimonialId]);
        $request->attributes->set(AuditAdminAction::CHANGES, [$before, null]);

        return redirect()->route(self::LIST)->with('success', __('Testimonial deleted.'));
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function audit(Request $request, string $id, ?array $before, WebsiteTestimonial $after): void
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['WebsiteTestimonial', $id]);
        $request->attributes->set(AuditAdminAction::CHANGES, [$before, $after->only(self::AUDITED)]);
    }

    private function logo(Request $request): ?UploadedFile
    {
        $file = $request->file('logo');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function find(string $testimonialId): WebsiteTestimonial
    {
        return $this->testimonials->find($testimonialId) ?? abort(404);
    }
}
