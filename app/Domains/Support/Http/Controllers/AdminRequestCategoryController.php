<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Controllers;

use App\Domains\Support\Services\RequestCategories;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin › Settings › Request categories: what a feature request can be
 * about. Add one, rename it in both languages, say whether it names the
 * other system, as an integration does; move it, retire it or bring it back.
 */
class AdminRequestCategoryController extends Controller
{
    public function __construct(
        private readonly RequestCategories $categories,
    ) {}

    public function index(): View
    {
        return view('admin.request-categories', [
            'categories' => $this->categories->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->categories->rules(adding: true));

        $this->categories->save((string) $validated['key'], $validated, (string) $request->user()->id);

        return redirect()->route('admin.request-categories.index')->with('success', 'Category added.');
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        abort_if($this->categories->find($key) === null, 404);

        $validated = $request->validate($this->categories->rules(adding: false));

        $this->categories->save($key, $validated, (string) $request->user()->id);

        return redirect()->route('admin.request-categories.index')->with('success', 'Category saved.');
    }

    public function retire(Request $request, string $key): RedirectResponse
    {
        try {
            $this->categories->retire($key, $request->boolean('retired'), (string) $request->user()->id);
        } catch (DomainException $e) {
            return redirect()->route('admin.request-categories.index')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.request-categories.index')->with('success', $request->boolean('retired')
            ? 'Category retired. Requests in it keep it; no new request is offered it.'
            : 'Category offered again.');
    }

    public function move(Request $request, string $key): RedirectResponse
    {
        try {
            $this->categories->move($key, $request->input('direction') === 'up' ? -1 : 1, (string) $request->user()->id);
        } catch (DomainException $e) {
            return redirect()->route('admin.request-categories.index')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.request-categories.index');
    }
}
