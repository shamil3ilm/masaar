<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * The one extra QR code an organization may print on its invoices, chosen
 * per paper: one of its business QR codes, or the invoice's own link.
 *
 * Always a code of its own, labelled, beside the country's: the country's
 * QR carries exactly what the authority defines and nothing is added to it.
 * One per paper, which is also all a 58 mm roll has room for.
 */
class InvoiceExtraQr
{
    /** The choice that prints each invoice's own link. */
    public const INVOICE_LINK = 'invoice_link';

    /** Smaller than the country's 32 mm, so the two are not mistaken. */
    public const SIZE_MM = 24.0;

    public function __construct(
        private readonly BusinessQrCodes $codes,
        private readonly InvoiceLinks $links,
    ) {}

    /**
     * @return array<string, string> paper => business QR code id or INVOICE_LINK
     */
    public function choices(Organization $organization): array
    {
        return array_filter((array) ($organization->invoice_qr ?? []), fn ($choice): bool => is_string($choice) && $choice !== '');
    }

    /**
     * @param  array<string, string|null>  $choices  paper => code id, INVOICE_LINK or empty for none
     */
    public function setChoices(Organization $organization, array $choices): void
    {
        $valid = [];

        foreach (PaperSize::cases() as $paper) {
            $choice = (string) ($choices[$paper->value] ?? '');

            if ($choice === self::INVOICE_LINK || ($choice !== '' && $this->codes->find((string) $organization->id, $choice) !== null)) {
                $valid[$paper->value] = $choice;
            }
        }

        $organization->forceFill(['invoice_qr' => $valid === [] ? null : $valid])->save();
    }

    /**
     * What to print for this invoice on this paper: a label and the code's
     * content, or null for none. A code deleted since it was chosen prints
     * nothing. Printing gives the invoice a link when it has none; a screen
     * that only shows the code ($create false) does not.
     *
     * @return array{label: string, payload: string, encoding: string}|null
     */
    public function for(Invoice $invoice, Organization $organization, PaperSize $paper, ?string $userId = null, bool $create = true): ?array
    {
        $choice = $this->choices($organization)[$paper->value] ?? null;

        if ($choice === self::INVOICE_LINK) {
            $link = $create
                ? $this->links->forInvoice($invoice, $userId)
                : $this->links->active((string) $organization->id, (string) $invoice->id)->first();

            return $link === null ? null : [
                'label' => 'View this invoice online / اعرض هذه الفاتورة عبر الإنترنت',
                'payload' => $this->links->url($link),
                'encoding' => BusinessQrCodes::ENCODING,
            ];
        }

        $code = $choice === null ? null : $this->codes->find((string) $organization->id, $choice);

        return $code === null ? null : [
            'label' => $code->label,
            'payload' => $this->codes->payload($code, $invoice),
            'encoding' => BusinessQrCodes::ENCODING,
        ];
    }
}
