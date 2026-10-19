<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Website\SectionCatalogue;
use App\Domains\Platform\Website\SectionDefinition;
use App\Domains\Platform\Website\WebsiteSections;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Website › Home page: the home page's sections - which are shown, in what
 * order, and what each says in English and Arabic.
 *
 * Ordering is by up and down buttons rather than dragging, so it works by
 * keyboard and on a phone. Saving, switching, moving and resetting each
 * write to the audit record (AuditAdminAction) with the section and, for a
 * change of text, the text before and after. Every route takes
 * website.manage.
 */
class AdminWebsiteSectionController extends Controller
{
    private const LIST = 'admin.website.sections.index';

    public function __construct(
        private readonly WebsiteSections $sections,
        private readonly SectionCatalogue $catalogue,
    ) {}

    public function index(): View
    {
        return view('admin.website.sections.index', ['states' => $this->sections->all()]);
    }

    public function edit(string $sectionKey): View
    {
        return view('admin.website.sections.edit', [
            'definition' => $this->definition($sectionKey),
            'texts' => $this->sections->texts($sectionKey),
            'edited' => $this->sections->texts($sectionKey) !== $this->catalogue->defaults($sectionKey),
        ]);
    }

    public function update(Request $request, string $sectionKey): RedirectResponse
    {
        $definition = $this->definition($sectionKey);
        $this->target($request, $sectionKey);

        [$before, $after] = $this->sections->save($sectionKey, $request->except(['_token']));
        $request->attributes->set(AuditAdminAction::CHANGES, [$before, $after]);

        return redirect()->route('admin.website.sections.edit', $sectionKey)
            ->with('success', __(':section saved. The home page shows it now.', ['section' => $definition->label]));
    }

    public function visibility(Request $request, string $sectionKey): RedirectResponse
    {
        $definition = $this->definition($sectionKey);
        $this->target($request, $sectionKey);
        $enabled = $request->boolean('enabled');

        try {
            $this->sections->setEnabled($sectionKey, $enabled);
        } catch (DomainException $e) {
            return redirect()->route(self::LIST)->with('error', $e->getMessage());
        }

        return redirect()->route(self::LIST)->with('success', $enabled
            ? __(':section is shown on the home page.', ['section' => $definition->label])
            : __(':section is hidden from the home page.', ['section' => $definition->label]));
    }

    public function move(Request $request, string $sectionKey): RedirectResponse
    {
        $this->definition($sectionKey);
        $this->target($request, $sectionKey);

        try {
            $this->sections->move($sectionKey, $request->input('direction') === 'up' ? 'up' : 'down');
        } catch (DomainException $e) {
            return redirect()->route(self::LIST)->with('error', $e->getMessage());
        }

        return redirect()->route(self::LIST);
    }

    public function reset(Request $request, string $sectionKey): RedirectResponse
    {
        $definition = $this->definition($sectionKey);
        $this->target($request, $sectionKey);
        $before = $this->sections->texts($sectionKey);

        $this->sections->reset($sectionKey);
        $request->attributes->set(AuditAdminAction::CHANGES, [$before, $this->sections->texts($sectionKey)]);

        return redirect()->route('admin.website.sections.edit', $sectionKey)
            ->with('success', __(':section is back to its default text.', ['section' => $definition->label]));
    }

    private function target(Request $request, string $sectionKey): void
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['WebsiteSection', $sectionKey]);
    }

    private function definition(string $sectionKey): SectionDefinition
    {
        return $this->catalogue->has($sectionKey) ? $this->catalogue->get($sectionKey) : abort(404);
    }
}
