<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Models\Core\Organization;
use App\Models\Core\PrintConfiguration;
use App\Models\Core\PrintTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class PrintService
{
    /**
     * Generate PDF from document.
     */
    public function generatePdf(
        string $documentType,
        mixed $document,
        ?string $paperSize = null,
        ?string $templateCode = null
    ): \Barryvdh\DomPDF\PDF {
        $template = $this->getTemplate($document->organization_id, $documentType, $paperSize, $templateCode);
        $data = $this->prepareDocumentData($documentType, $document, $template);

        $pdf = Pdf::loadView($template->getViewPath(), $data);

        // Configure PDF settings
        $dimensions = $template->getPaperDimensions();
        $settings = $template->getMergedSettings();

        if ($template->isThermal()) {
            // Thermal receipts have dynamic height
            $pdf->setPaper([0, 0, $dimensions['width'] * 2.83465, 1000], 'portrait');
        } else {
            $pdf->setPaper(strtolower($template->paper_size), $template->orientation);
        }

        // Update print count
        if (method_exists($document, 'incrementPrintCount')) {
            $document->incrementPrintCount();
        }

        return $pdf;
    }

    /**
     * Generate HTML for preview or direct printing.
     */
    public function generateHtml(
        string $documentType,
        mixed $document,
        ?string $paperSize = null,
        ?string $templateCode = null
    ): string {
        $template = $this->getTemplate($document->organization_id, $documentType, $paperSize, $templateCode);
        $data = $this->prepareDocumentData($documentType, $document, $template);

        return View::make($template->getViewPath(), $data)->render();
    }

    /**
     * Generate thermal receipt data for Sunmi/ESC-POS printers.
     */
    public function generateThermalData(
        string $documentType,
        mixed $document,
        ?string $printerType = null
    ): ThermalPrintData {
        $config = $this->getPrinterConfig($document->organization_id, $printerType);
        $organization = Organization::find($document->organization_id);

        $builder = new ThermalPrintBuilder($config);

        return match ($documentType) {
            PrintTemplate::DOC_INVOICE => $this->buildInvoiceReceipt($builder, $document, $organization),
            PrintTemplate::DOC_PAYMENT_RECEIPT => $this->buildPaymentReceipt($builder, $document, $organization),
            default => throw new \InvalidArgumentException("Unsupported document type for thermal: {$documentType}"),
        };
    }

    /**
     * Get the appropriate template.
     */
    protected function getTemplate(
        int $organizationId,
        string $documentType,
        ?string $paperSize,
        ?string $templateCode
    ): PrintTemplate {
        // If template code specified, use it
        if ($templateCode) {
            $template = PrintTemplate::where('organization_id', $organizationId)
                ->where('code', $templateCode)
                ->active()
                ->first();

            if ($template) {
                return $template;
            }
        }

        // Find default template for document type and paper size
        $query = PrintTemplate::where('organization_id', $organizationId)
            ->forDocumentType($documentType)
            ->active();

        if ($paperSize) {
            $query->forPaperSize($paperSize);
        }

        $template = $query->default()->first()
            ?? $query->first();

        // Fallback to system default
        if (!$template) {
            return $this->createDefaultTemplate($organizationId, $documentType, $paperSize ?? 'a4');
        }

        return $template;
    }

    /**
     * Create a default template if none exists.
     */
    protected function createDefaultTemplate(int $organizationId, string $documentType, string $paperSize): PrintTemplate
    {
        $defaults = collect(PrintTemplate::DEFAULT_TEMPLATES)
            ->first(fn($t) => $t['document_type'] === $documentType && $t['paper_size'] === $paperSize);

        if (!$defaults) {
            $defaults = [
                'code' => "{$documentType}_{$paperSize}",
                'name' => ucfirst($documentType) . ' - ' . strtoupper($paperSize),
                'document_type' => $documentType,
                'paper_size' => $paperSize,
                'template_file' => "exports.templates.{$documentType}-{$paperSize}",
            ];
        }

        return PrintTemplate::create(array_merge($defaults, [
            'organization_id' => $organizationId,
            'is_default' => true,
        ]));
    }

    /**
     * Get printer configuration.
     */
    protected function getPrinterConfig(int $organizationId, ?string $printerType): PrintConfiguration
    {
        $query = PrintConfiguration::where('organization_id', $organizationId)
            ->active();

        if ($printerType) {
            $query->where('printer_type', $printerType);
        } else {
            $query->thermal()->default();
        }

        $config = $query->first();

        if (!$config) {
            // Return a default config
            return new PrintConfiguration([
                'printer_type' => $printerType ?? PrintConfiguration::PRINTER_THERMAL_80,
                'default_paper_size' => 'thermal_80',
                'auto_cut' => true,
                'copies' => 1,
            ]);
        }

        return $config;
    }

    /**
     * Prepare document data for rendering.
     */
    protected function prepareDocumentData(string $documentType, mixed $document, PrintTemplate $template): array
    {
        $organization = Organization::find($document->organization_id);
        $settings = $template->getMergedSettings();

        $baseData = [
            'organization' => $organization,
            'template' => $template,
            'settings' => $settings,
            'showLogo' => $template->show_logo,
            'showQrCode' => $template->show_qr_code,
            'showSignature' => $template->show_signature,
            'showWatermark' => $template->show_watermark,
            'watermarkText' => $template->watermark_text,
            'primaryColor' => $template->primary_color,
            'secondaryColor' => $template->secondary_color,
            'paperSize' => $template->paper_size,
            'isThermal' => $template->isThermal(),
        ];

        return match ($documentType) {
            PrintTemplate::DOC_INVOICE => array_merge($baseData, [
                'invoice' => $document,
                'lines' => $document->lines,
                'customer' => $document->customer,
                'payments' => $document->payments ?? collect(),
            ]),
            PrintTemplate::DOC_QUOTATION => array_merge($baseData, [
                'quotation' => $document,
                'lines' => $document->lines,
                'customer' => $document->customer,
            ]),
            PrintTemplate::DOC_PURCHASE_ORDER => array_merge($baseData, [
                'purchaseOrder' => $document,
                'lines' => $document->lines,
                'supplier' => $document->supplier,
            ]),
            PrintTemplate::DOC_CREDIT_NOTE => array_merge($baseData, [
                'creditNote' => $document,
                'lines' => $document->lines,
                'customer' => $document->customer,
                'originalInvoice' => $document->originalInvoice,
            ]),
            PrintTemplate::DOC_DELIVERY_NOTE => array_merge($baseData, [
                'deliveryNote' => $document,
                'lines' => $document->lines,
                'customer' => $document->customer,
            ]),
            PrintTemplate::DOC_PAYMENT_RECEIPT => array_merge($baseData, [
                'payment' => $document,
                'allocations' => $document->allocations ?? collect(),
                'customer' => $document->customer,
            ]),
            PrintTemplate::DOC_PAYSLIP => array_merge($baseData, [
                'payslip' => $document,
                'employee' => $document->employee,
                'earnings' => $document->earnings ?? [],
                'deductions' => $document->deductions ?? [],
            ]),
            PrintTemplate::DOC_STATEMENT => array_merge($baseData, [
                'statement' => $document,
                'transactions' => $document['transactions'] ?? [],
                'customer' => $document['customer'] ?? null,
            ]),
            default => $baseData,
        };
    }

    /**
     * Build invoice receipt for thermal printer.
     */
    protected function buildInvoiceReceipt(
        ThermalPrintBuilder $builder,
        mixed $invoice,
        Organization $organization
    ): ThermalPrintData {
        // Header
        $builder->alignCenter()
            ->bold()
            ->textLarge($organization->name)
            ->bold(false)
            ->newLine();

        if ($organization->address_line_1) {
            $builder->text($organization->address_line_1);
        }
        if ($organization->city) {
            $builder->text($organization->city);
        }
        if ($organization->phone) {
            $builder->text("Tel: {$organization->phone}");
        }
        if ($organization->tax_number) {
            $builder->text("Tax No: {$organization->tax_number}");
        }

        $builder->dividerDash()
            ->bold()
            ->text($invoice->invoice_type === 'simplified' ? 'SIMPLIFIED TAX INVOICE' : 'TAX INVOICE')
            ->bold(false)
            ->dividerDash()
            ->alignLeft();

        // Invoice details
        $builder->textColumns('Invoice No:', $invoice->invoice_number)
            ->textColumns('Date:', $invoice->invoice_date->format('d/m/Y H:i'));

        if ($invoice->customer_name) {
            $builder->textColumns('Customer:', Str::limit($invoice->customer_name, 20));
        }
        if ($invoice->customer_tax_number) {
            $builder->textColumns('Tax No:', $invoice->customer_tax_number);
        }

        $builder->dividerDouble()
            ->bold()
            ->textThreeColumns('Item', 'Qty', 'Amount')
            ->bold(false)
            ->dividerDash();

        // Line items
        foreach ($invoice->lines as $line) {
            $builder->text(Str::limit($line->description, 30))
                ->textThreeColumns(
                    "@ " . number_format((float)$line->unit_price, 2),
                    (string)number_format((float)$line->quantity, 0),
                    number_format((float)$line->total, 2)
                );
        }

        $builder->dividerDouble()
            ->textColumns('Subtotal:', number_format((float)$invoice->subtotal, 2));

        if ((float)$invoice->discount_amount > 0) {
            $builder->textColumns('Discount:', '-' . number_format((float)$invoice->discount_amount, 2));
        }

        $taxRate = $invoice->lines->first()?->tax_rate ?? 15;
        $builder->textColumns("VAT ({$taxRate}%):", number_format((float)$invoice->tax_amount, 2))
            ->dividerDash()
            ->bold()
            ->textLarge("TOTAL {$invoice->currency_code}: " . number_format((float)$invoice->total, 2))
            ->bold(false);

        if ((float)$invoice->amount_paid > 0) {
            $builder->textColumns('Paid:', number_format((float)$invoice->amount_paid, 2))
                ->bold()
                ->textColumns('Balance:', number_format((float)$invoice->amount_due, 2))
                ->bold(false);
        }

        $builder->dividerDash();

        // QR Code
        if ($invoice->compliance_qr_code) {
            $builder->alignCenter()
                ->qrCode($invoice->compliance_qr_code)
                ->newLine();
        }

        // Footer
        $builder->alignCenter()
            ->text('Thank you for your business!')
            ->newLine();

        if ($invoice->compliance_uuid) {
            $builder->textSmall("UUID: " . Str::limit($invoice->compliance_uuid, 36));
        }

        $builder->dividerDash()
            ->text($organization->name)
            ->newLine()
            ->newLine()
            ->newLine()
            ->cut();

        return $builder->build();
    }

    /**
     * Build payment receipt for thermal printer.
     */
    protected function buildPaymentReceipt(
        ThermalPrintBuilder $builder,
        mixed $payment,
        Organization $organization
    ): ThermalPrintData {
        $builder->alignCenter()
            ->bold()
            ->textLarge($organization->name)
            ->bold(false)
            ->newLine();

        if ($organization->tax_number) {
            $builder->text("Tax No: {$organization->tax_number}");
        }

        $builder->dividerDash()
            ->bold()
            ->text('PAYMENT RECEIPT')
            ->bold(false)
            ->dividerDash()
            ->alignLeft();

        $builder->textColumns('Receipt No:', $payment->payment_number)
            ->textColumns('Date:', $payment->payment_date->format('d/m/Y H:i'))
            ->textColumns('Method:', ucfirst($payment->payment_method ?? 'Cash'));

        if ($payment->customer) {
            $builder->dividerDash()
                ->text("From: {$payment->customer->company_name}");
        }

        $builder->dividerDouble()
            ->alignCenter()
            ->bold()
            ->textLarge("{$payment->currency_code} " . number_format((float)$payment->amount, 2))
            ->bold(false)
            ->dividerDouble();

        // Allocated invoices
        if ($payment->allocations && $payment->allocations->count() > 0) {
            $builder->alignLeft()
                ->bold()
                ->text('Applied to:')
                ->bold(false);

            foreach ($payment->allocations as $allocation) {
                $builder->textColumns(
                    $allocation->invoice->invoice_number ?? 'Invoice',
                    number_format((float)$allocation->amount, 2)
                );
            }

            $builder->dividerDash();
        }

        if ($payment->reference) {
            $builder->alignLeft()
                ->textColumns('Reference:', $payment->reference);
        }

        $builder->alignCenter()
            ->newLine()
            ->text('Thank you!')
            ->newLine()
            ->newLine()
            ->cut();

        return $builder->build();
    }

    /**
     * Generate batch print for multiple documents.
     */
    public function generateBatchPdf(
        string $documentType,
        array $documents,
        ?string $paperSize = null
    ): \Barryvdh\DomPDF\PDF {
        $html = '';

        foreach ($documents as $index => $document) {
            if ($index > 0) {
                $html .= '<div style="page-break-before: always;"></div>';
            }

            $html .= $this->generateHtml($documentType, $document, $paperSize);
        }

        $pdf = Pdf::loadHTML($html);

        if ($paperSize && !str_contains($paperSize, 'thermal')) {
            $pdf->setPaper($paperSize, 'portrait');
        }

        return $pdf;
    }
}

/**
 * Builder for thermal print commands.
 */
class ThermalPrintBuilder
{
    protected array $commands = [];
    protected PrintConfiguration $config;
    protected string $currentAlign = 'left';
    protected bool $isBold = false;

    public function __construct(PrintConfiguration $config)
    {
        $this->config = $config;
        $this->commands[] = ['type' => 'init'];
    }

    public function alignCenter(): self
    {
        $this->currentAlign = 'center';
        $this->commands[] = ['type' => 'align', 'value' => 'center'];
        return $this;
    }

    public function alignLeft(): self
    {
        $this->currentAlign = 'left';
        $this->commands[] = ['type' => 'align', 'value' => 'left'];
        return $this;
    }

    public function alignRight(): self
    {
        $this->currentAlign = 'right';
        $this->commands[] = ['type' => 'align', 'value' => 'right'];
        return $this;
    }

    public function bold(bool $enable = true): self
    {
        $this->isBold = $enable;
        $this->commands[] = ['type' => 'bold', 'value' => $enable];
        return $this;
    }

    public function text(string $text): self
    {
        $this->commands[] = ['type' => 'text', 'value' => $text, 'size' => 'normal'];
        return $this;
    }

    public function textLarge(string $text): self
    {
        $this->commands[] = ['type' => 'text', 'value' => $text, 'size' => 'large'];
        return $this;
    }

    public function textSmall(string $text): self
    {
        $this->commands[] = ['type' => 'text', 'value' => $text, 'size' => 'small'];
        return $this;
    }

    public function textColumns(string $left, string $right): self
    {
        $maxChars = $this->config->getMaxCharsPerLine();
        $leftLen = mb_strlen($left);
        $rightLen = mb_strlen($right);
        $spaces = max(1, $maxChars - $leftLen - $rightLen);

        $this->commands[] = [
            'type' => 'text',
            'value' => $left . str_repeat(' ', $spaces) . $right,
            'size' => 'normal',
        ];

        return $this;
    }

    public function textThreeColumns(string $left, string $center, string $right): self
    {
        $maxChars = $this->config->getMaxCharsPerLine();
        $colWidth = (int)floor($maxChars / 3);

        $left = str_pad(Str::limit($left, $colWidth - 1), $colWidth);
        $center = str_pad(Str::limit($center, $colWidth - 1), $colWidth, ' ', STR_PAD_BOTH);
        $right = str_pad(Str::limit($right, $colWidth - 1), $colWidth, ' ', STR_PAD_LEFT);

        $this->commands[] = [
            'type' => 'text',
            'value' => $left . $center . $right,
            'size' => 'normal',
        ];

        return $this;
    }

    public function newLine(int $count = 1): self
    {
        for ($i = 0; $i < $count; $i++) {
            $this->commands[] = ['type' => 'newline'];
        }
        return $this;
    }

    public function dividerDash(): self
    {
        $maxChars = $this->config->getMaxCharsPerLine();
        $this->commands[] = ['type' => 'text', 'value' => str_repeat('-', $maxChars), 'size' => 'normal'];
        return $this;
    }

    public function dividerDouble(): self
    {
        $maxChars = $this->config->getMaxCharsPerLine();
        $this->commands[] = ['type' => 'text', 'value' => str_repeat('=', $maxChars), 'size' => 'normal'];
        return $this;
    }

    public function qrCode(string $data): self
    {
        $this->commands[] = ['type' => 'qrcode', 'value' => $data, 'size' => 6];
        return $this;
    }

    public function barcode(string $data, string $type = 'CODE128'): self
    {
        $this->commands[] = ['type' => 'barcode', 'value' => $data, 'format' => $type];
        return $this;
    }

    public function image(string $base64): self
    {
        $this->commands[] = ['type' => 'image', 'value' => $base64];
        return $this;
    }

    public function cut(bool $partial = false): self
    {
        if ($this->config->auto_cut) {
            $this->commands[] = ['type' => 'cut', 'partial' => $partial];
        }
        return $this;
    }

    public function openDrawer(): self
    {
        if ($this->config->open_drawer) {
            $this->commands[] = ['type' => 'drawer'];
        }
        return $this;
    }

    public function build(): ThermalPrintData
    {
        return new ThermalPrintData(
            commands: $this->commands,
            printerType: $this->config->printer_type,
            paperWidth: $this->config->getPaperWidth(),
            maxCharsPerLine: $this->config->getMaxCharsPerLine()
        );
    }
}

/**
 * Data object for thermal print output.
 */
class ThermalPrintData
{
    public function __construct(
        public readonly array $commands,
        public readonly string $printerType,
        public readonly int $paperWidth,
        public readonly int $maxCharsPerLine
    ) {}

    public function toArray(): array
    {
        return [
            'commands' => $this->commands,
            'printer_type' => $this->printerType,
            'paper_width' => $this->paperWidth,
            'max_chars_per_line' => $this->maxCharsPerLine,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Convert to ESC/POS command bytes (for direct printing).
     */
    public function toEscPos(): string
    {
        $output = '';

        // Initialize
        $output .= "\x1B\x40"; // ESC @

        foreach ($this->commands as $cmd) {
            $output .= match ($cmd['type']) {
                'align' => match ($cmd['value']) {
                    'center' => "\x1B\x61\x01",
                    'right' => "\x1B\x61\x02",
                    default => "\x1B\x61\x00",
                },
                'bold' => $cmd['value'] ? "\x1B\x45\x01" : "\x1B\x45\x00",
                'text' => $this->formatTextCommand($cmd),
                'newline' => "\n",
                'cut' => $cmd['partial'] ?? false ? "\x1B\x6D" : "\x1D\x56\x00",
                'drawer' => "\x1B\x70\x00\x19\xFA",
                default => '',
            };
        }

        return $output;
    }

    protected function formatTextCommand(array $cmd): string
    {
        $text = $cmd['value'] ?? '';
        $prefix = '';
        $suffix = '';

        if (($cmd['size'] ?? 'normal') === 'large') {
            $prefix = "\x1D\x21\x11"; // Double height & width
            $suffix = "\x1D\x21\x00"; // Reset
        } elseif (($cmd['size'] ?? 'normal') === 'small') {
            $prefix = "\x1B\x4D\x01"; // Small font
            $suffix = "\x1B\x4D\x00"; // Reset
        }

        return $prefix . $text . "\n" . $suffix;
    }
}
