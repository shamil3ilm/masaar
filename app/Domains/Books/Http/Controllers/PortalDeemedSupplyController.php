<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Books\Enums\DeemedSupplyKind;
use App\Domains\Books\Models\DeemedSupply;
use App\Domains\Books\Services\DeemedSupplies;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Deemed supplies in Books, from the browser (DeemedSupplies): those
 * recorded, with their outcome, and the form to record one. A taxed one
 * opens its kept invoice, to check and issue.
 */
class PortalDeemedSupplyController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly DeemedSupplies $deemed,
    ) {}

    public function index(Request $request): View
    {
        $organization = $this->portalOrganization($this->tenant, $request);

        return view('portal.books.deemed-supplies', [
            'records' => $this->deemed->list((string) $organization->id),
            'kinds' => DeemedSupplyKind::options(),
            'rules' => $this->deemed->rulesFor($organization),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $record = $this->deemed->record($organization, $request->validate($this->deemed->rules($request->input('kind'))));

        if ($record->outcome === DeemedSupply::TAXED && $record->invoice_id !== null) {
            return redirect()->route('portal.invoices.show', $record->invoice_id)
                ->with('success', __('Deemed supply recorded. Its invoice, which states the output tax, is a draft: issue it and keep it.'));
        }

        return redirect()->route('portal.books.deemed-supplies')->with('success', $record->reason);
    }
}
