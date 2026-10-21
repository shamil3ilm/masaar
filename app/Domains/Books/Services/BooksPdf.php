<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Support\Brand;
use Illuminate\Contracts\Translation\Translator;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Books page as an A4 PDF - a customer statement, a VAT return draft - in
 * the language the person reads the portal in, right to left in Arabic.
 * Drawn by mPDF with the font the invoices use, which carries Arabic, and
 * the same font cache (InvoicePdf).
 */
class BooksPdf
{
    public function __construct(
        private readonly Translator $translator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function download(string $view, array $data, string $title, string $fileName): Response
    {
        $rtl = $this->translator->getLocale() === 'ar';
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 12,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $this->tempDir(),
        ]);
        $mpdf->SetDirectionality($rtl ? 'rtl' : 'ltr');
        $mpdf->SetTitle($title);
        $mpdf->SetCreator(Brand::name('en'));
        $mpdf->WriteHTML(view($view, [...$data, 'rtl' => $rtl, 'title' => $title])->render());

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function tempDir(): string
    {
        $dir = (string) config('invoices.pdf_cache_dir', storage_path('framework/cache/pdf'));

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
