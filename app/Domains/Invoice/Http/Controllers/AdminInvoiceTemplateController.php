<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Contracts\Presentation;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Services\InvoicePrintView;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use Illuminate\Http\Request;

/**
 * Admin › Branding › Invoice template: the platform's default, which every
 * organization's invoices follow until it sets its own.
 *
 * The preview draws the deployment's default country, or the first other
 * that hands over a printed copy, with a made-up seller.
 */
class AdminInvoiceTemplateController extends InvoiceTemplateController
{
    public function __construct(
        InvoiceTemplates $templates,
        InvoicePrintView $printView,
        InvoicePdf $pdf,
        private readonly JurisdictionRegistry $registry,
    ) {
        parent::__construct($templates, $printView, $pdf);
    }

    protected function owner(Request $request): array
    {
        // The template is a customization; its key is in the recorded input.
        $request->attributes->set(AuditAdminAction::TARGET, ['Customization', null]);

        return ['level' => Level::Platform, 'scope' => Scope::platform(), 'organization' => null];
    }

    protected function country(?Organization $organization): ?Presentation
    {
        // The deployment's own country first, then the others in order.
        $codes = array_values(array_unique([$this->registry->defaultCode(), ...$this->registry->registered()]));

        foreach ($codes as $code) {
            $presentation = $this->registry->get($code)->presentation();

            if ($presentation->sampleInvoice() !== null) {
                return $presentation;
            }
        }

        return null;
    }

    protected function route(): string
    {
        return 'admin.invoice-template';
    }

    protected function view(): string
    {
        return 'admin.invoice-template';
    }
}
