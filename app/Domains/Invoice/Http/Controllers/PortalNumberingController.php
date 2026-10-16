<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Services\NumberingSettings;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The organization's invoice numbering, from the portal.
 *
 * The organization's own sequences; a branch's own is set over the API.
 * Its administrators only, for the organization the session works in.
 */
class PortalNumberingController extends Controller
{
    public function __construct(
        private readonly NumberingSettings $settings,
    ) {}

    public function edit(Request $request): View
    {
        return view('portal.numbering', [
            'sequences' => $this->settings->sequences($this->organization($request)),
        ]);
    }

    public function update(Request $request, string $documentType): RedirectResponse
    {
        $organizationId = $this->organization($request);

        abort_unless(in_array($documentType, NumberingSettings::TYPES, true), 404);

        $validated = $request->validateWithBag(
            $documentType,
            $this->settings->rules($organizationId, $documentType, $request->input('reset')),
            NumberingSettings::messages(),
        );

        $this->settings->save($organizationId, $documentType, $validated);

        return redirect()->route('portal.numbering.edit')->with('success', match ($documentType) {
            'credit_note' => __('Credit note numbering saved.'),
            'debit_note' => __('Debit note numbering saved.'),
            'prepayment' => __('Prepayment numbering saved.'),
            default => __('Invoice numbering saved.'),
        });
    }

    private function organization(Request $request): string
    {
        $organizationId = $request->attributes->get(PortalTenant::ORG_ID);

        abort_unless(is_string($organizationId), 404);
        abort_unless($request->user()?->roleIn($organizationId) === 'admin', 403, __('Only an administrator of this organization can change its numbering.'));

        return $organizationId;
    }
}
