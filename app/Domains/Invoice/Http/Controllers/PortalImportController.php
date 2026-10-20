<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Imports\ImportColumns;
use App\Domains\Invoice\Services\CsvImports;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\BranchScope;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Portal › Import: invoices, customers and products from a CSV file.
 *
 * A template per kind, with the organization's country's columns; the file
 * checked row by row and shown with its problems and the records it would
 * make; and only on confirmation saved - invoices as drafts at the person's
 * branch, as the invoice form would draft them, and never issued. Anyone who
 * may write in the organization may import, as they may type the same
 * records in.
 */
class PortalImportController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly CsvImports $imports,
        private readonly ImportColumns $columns,
        private readonly BranchAccess $branchAccess,
        private readonly BranchScope $branchScope,
    ) {}

    public function index(Request $request): View
    {
        $organization = $this->portalOrganization($this->tenant, $request);

        $kinds = [];

        foreach (ImportColumns::KINDS as $kind) {
            $kinds[$kind] = [
                'label' => ImportColumns::label($kind),
                'columns' => $this->columns->columns($kind, $organization),
                'required' => $this->columns->required($kind, $organization),
            ];
        }

        return view('portal.imports.index', [
            'organization' => $organization,
            'kinds' => $kinds,
            'imports' => $this->imports->recent((string) $organization->id),
        ]);
    }

    /** The kind's template for the organization's country, as a CSV to fill in. */
    public function template(Request $request, string $kind): Response
    {
        abort_unless(in_array($kind, ImportColumns::KINDS, true), 404);
        $organization = $this->portalOrganization($this->tenant, $request);

        return response($this->columns->template($kind, $organization), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$kind.'-template.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $data = $request->validate([
            'kind' => ['required', Rule::in(ImportColumns::KINDS)],
            'file' => ['required', 'file', 'max:'.(int) config('imports.max_kilobytes')],
        ]);

        try {
            // Invoices are drafted at the branch the invoice form would
            // choose for this person, and refused where it would refuse them.
            $branchId = $data['kind'] === ImportColumns::INVOICES
                ? $this->branchAccess->draftBranch((string) $request->user()?->getAuthIdentifier(), (string) $organization->id, $this->branchScope->selected())
                : null;

            $import = $this->imports->upload($organization, $data['kind'], $request->file('file'), $branchId);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.imports.show', $import->id);
    }

    public function show(Request $request, string $importId): View
    {
        $organization = $this->portalOrganization($this->tenant, $request);

        return view('portal.imports.show', [
            'organization' => $organization,
            'import' => $this->imports->find((string) $organization->id, $importId) ?? abort(404),
        ]);
    }

    public function confirm(Request $request, string $importId): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $import = $this->imports->find((string) $organization->id, $importId) ?? abort(404);

        if (! $this->imports->confirm($import)) {
            return back()->with('error', __('This import cannot be saved: it has no rows that passed, or it was saved already.'));
        }

        return redirect()->route('portal.imports.show', $import->id)
            ->with('success', __('Saving the rows that passed. Invoices are saved as drafts; none is issued.'));
    }
}
