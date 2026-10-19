<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Organization\Services\BusinessTypes;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin > Settings > Business types: the platform's list of what an
 * organization can say it is, and the defaults each suggests.
 *
 * Add one, rename it in both languages, move it up or down, retire it (no
 * longer offered; an organization that chose it keeps it) or bring it back,
 * and change its defaults. None of it can refuse an organization anything.
 */
class AdminBusinessTypeController extends Controller
{
    public function __construct(
        private readonly BusinessTypes $types,
    ) {}

    public function index(): View
    {
        return view('admin.business-types', [
            'types' => $this->types->all(),
            'papers' => collect(PaperSize::cases())->mapWithKeys(fn (PaperSize $paper): array => [$paper->value => $paper->label()])->all(),
            'panels' => BusinessTypes::PANELS,
            'steps' => BusinessTypes::STEPS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->types->rules(adding: true));

        $this->types->save(['key' => $validated['key'], ...BusinessTypes::entryFrom($validated)], (string) $request->user()->id);

        return redirect()->route('admin.business-types.index')->with('success', __('Business type added.'));
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        abort_if($this->types->find($key) === null, 404);

        $validated = $request->validate($this->types->rules(adding: false));

        $this->types->save(['key' => $key, ...BusinessTypes::entryFrom($validated)], (string) $request->user()->id);

        return redirect()->route('admin.business-types.index')->with('success', __('Business type saved.'));
    }

    public function retire(Request $request, string $key): RedirectResponse
    {
        try {
            $this->types->retire($key, $request->boolean('retired'), (string) $request->user()->id);
        } catch (DomainException $e) {
            return redirect()->route('admin.business-types.index')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.business-types.index')->with('success', $request->boolean('retired')
            ? __('Business type retired. Organizations that chose it keep it; no one else is offered it.')
            : __('Business type offered again.'));
    }

    public function move(Request $request, string $key): RedirectResponse
    {
        try {
            $this->types->move($key, $request->input('direction') === 'up' ? -1 : 1, (string) $request->user()->id);
        } catch (DomainException $e) {
            return redirect()->route('admin.business-types.index')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.business-types.index');
    }
}
