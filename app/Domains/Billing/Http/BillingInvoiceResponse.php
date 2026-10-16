<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http;

use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Services\Billing;
use App\Domains\Invoice\Services\InvoicePdf;
use DomainException;
use Illuminate\Http\Response;

/**
 * The platform's invoice for a payment, as a PDF download.
 *
 * The invoice belongs to the billing organization, which issued it, so the
 * PDF is drawn with that organization as the seller - whoever downloads it.
 */
final class BillingInvoiceResponse
{
    /**
     * @return Response|null Null when there is no invoice, or it may not be given out yet
     */
    public static function for(Payment $payment, Billing $billing, InvoicePdf $pdf): ?Response
    {
        $invoice = $payment->invoice();
        $seller = $billing->billingOrganization();

        if ($invoice === null || $seller === null || (string) $invoice->org_id !== (string) $seller->id) {
            return null;
        }

        try {
            $file = $pdf->render($invoice, $seller);
        } catch (DomainException) {
            return null;
        }

        return response($file, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->fileName($invoice).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
