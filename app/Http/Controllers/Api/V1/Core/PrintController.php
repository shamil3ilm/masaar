<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\PrintConfiguration;
use App\Models\Core\PrintTemplate;
use App\Models\Sales\Invoice;
use App\Models\Sales\PaymentReceived;
use App\Models\Sales\Quotation;
use App\Models\Purchase\PurchaseOrder;
use App\Services\Print\PrintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class PrintController extends Controller
{
    public function __construct(
        protected PrintService $printService
    ) {}

    /**
     * Get available templates for organization.
     */
    public function templates(Request $request): JsonResponse
    {
        $templates = PrintTemplate::where('organization_id', $request->user()->organization_id)
            ->active()
            ->orderBy('document_type')
            ->orderBy('paper_size')
            ->get()
            ->groupBy('document_type');

        return response()->json([
            'data' => $templates,
            'document_types' => PrintTemplate::getDocumentTypes(),
            'paper_sizes' => PrintTemplate::getPaperSizeOptions(),
        ]);
    }

    /**
     * Get single template.
     */
    public function showTemplate(Request $request, int $id): JsonResponse
    {
        $template = PrintTemplate::where('organization_id', $request->user()->organization_id)
            ->findOrFail($id);

        return response()->json(['data' => $template]);
    }

    /**
     * Create custom template.
     */
    public function storeTemplate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:print_templates,code,NULL,id,organization_id,' . $request->user()->organization_id,
            'document_type' => 'required|string|in:' . implode(',', array_keys(PrintTemplate::getDocumentTypes())),
            'paper_size' => 'required|string|in:' . implode(',', array_keys(PrintTemplate::PAPER_SIZES)),
            'orientation' => 'sometimes|string|in:portrait,landscape',
            'template_content' => 'nullable|string',
            'settings' => 'nullable|array',
            'show_logo' => 'sometimes|boolean',
            'show_qr_code' => 'sometimes|boolean',
            'show_signature' => 'sometimes|boolean',
            'show_watermark' => 'sometimes|boolean',
            'watermark_text' => 'nullable|string|max:50',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['organization_id'] = $request->user()->organization_id;

        // If setting as default, unset other defaults
        if ($data['is_default'] ?? false) {
            PrintTemplate::where('organization_id', $data['organization_id'])
                ->where('document_type', $data['document_type'])
                ->where('paper_size', $data['paper_size'])
                ->update(['is_default' => false]);
        }

        $template = PrintTemplate::create($data);

        return response()->json(['data' => $template], 201);
    }

    /**
     * Update template.
     */
    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $template = PrintTemplate::where('organization_id', $request->user()->organization_id)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'settings' => 'nullable|array',
            'template_content' => 'nullable|string',
            'show_logo' => 'sometimes|boolean',
            'show_qr_code' => 'sometimes|boolean',
            'show_signature' => 'sometimes|boolean',
            'show_watermark' => 'sometimes|boolean',
            'watermark_text' => 'nullable|string|max:50',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // If setting as default, unset other defaults
        if ($data['is_default'] ?? false) {
            PrintTemplate::where('organization_id', $template->organization_id)
                ->where('document_type', $template->document_type)
                ->where('paper_size', $template->paper_size)
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);
        }

        $template->update($data);

        return response()->json(['data' => $template]);
    }

    /**
     * Delete template.
     */
    public function destroyTemplate(Request $request, int $id): JsonResponse
    {
        $template = PrintTemplate::where('organization_id', $request->user()->organization_id)
            ->findOrFail($id);

        $template->delete();

        return response()->json(['message' => 'Template deleted']);
    }

    /**
     * Get printer configurations.
     */
    public function configurations(Request $request): JsonResponse
    {
        $configs = PrintConfiguration::where('organization_id', $request->user()->organization_id)
            ->with('branch')
            ->get();

        return response()->json([
            'data' => $configs,
            'printer_types' => PrintConfiguration::getPrinterTypes(),
        ]);
    }

    /**
     * Store printer configuration.
     */
    public function storeConfiguration(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'nullable|exists:branches,id',
            'printer_type' => 'required|string|in:' . implode(',', array_keys(PrintConfiguration::getPrinterTypes())),
            'default_paper_size' => 'required|string',
            'paper_sizes' => 'nullable|array',
            'thermal_settings' => 'nullable|array',
            'margin_settings' => 'nullable|array',
            'font_settings' => 'nullable|array',
            'auto_cut' => 'sometimes|boolean',
            'open_drawer' => 'sometimes|boolean',
            'copies' => 'sometimes|integer|min:1|max:5',
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['organization_id'] = $request->user()->organization_id;

        if ($data['is_default'] ?? false) {
            PrintConfiguration::where('organization_id', $data['organization_id'])
                ->where('branch_id', $data['branch_id'] ?? null)
                ->update(['is_default' => false]);
        }

        $config = PrintConfiguration::create($data);

        return response()->json(['data' => $config], 201);
    }

    /**
     * Update printer configuration.
     */
    public function updateConfiguration(Request $request, int $id): JsonResponse
    {
        $config = PrintConfiguration::where('organization_id', $request->user()->organization_id)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'printer_type' => 'sometimes|string',
            'default_paper_size' => 'sometimes|string',
            'thermal_settings' => 'nullable|array',
            'margin_settings' => 'nullable|array',
            'font_settings' => 'nullable|array',
            'auto_cut' => 'sometimes|boolean',
            'open_drawer' => 'sometimes|boolean',
            'copies' => 'sometimes|integer|min:1|max:5',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $config->update($validator->validated());

        return response()->json(['data' => $config]);
    }

    /**
     * Print/Preview invoice.
     */
    public function invoice(Request $request, int $id): Response|JsonResponse
    {
        $invoice = Invoice::where('organization_id', $request->user()->organization_id)
            ->with(['lines.product', 'lines.unit', 'customer', 'payments'])
            ->findOrFail($id);

        $paperSize = $request->get('paper_size', 'a4');
        $templateCode = $request->get('template');
        $format = $request->get('format', 'pdf'); // pdf, html, thermal

        if ($format === 'thermal') {
            $printerType = $request->get('printer_type');
            $data = $this->printService->generateThermalData('invoice', $invoice, $printerType);
            return response()->json(['data' => $data->toArray()]);
        }

        if ($format === 'html') {
            $html = $this->printService->generateHtml('invoice', $invoice, $paperSize, $templateCode);
            return response($html)->header('Content-Type', 'text/html');
        }

        $pdf = $this->printService->generatePdf('invoice', $invoice, $paperSize, $templateCode);

        $filename = "invoice-{$invoice->invoice_number}.pdf";

        if ($request->boolean('download')) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    /**
     * Print/Preview quotation.
     */
    public function quotation(Request $request, int $id): Response|JsonResponse
    {
        $quotation = Quotation::where('organization_id', $request->user()->organization_id)
            ->with(['lines.product', 'lines.unit', 'customer'])
            ->findOrFail($id);

        $paperSize = $request->get('paper_size', 'a4');
        $templateCode = $request->get('template');
        $format = $request->get('format', 'pdf');

        if ($format === 'html') {
            $html = $this->printService->generateHtml('quotation', $quotation, $paperSize, $templateCode);
            return response($html)->header('Content-Type', 'text/html');
        }

        $pdf = $this->printService->generatePdf('quotation', $quotation, $paperSize, $templateCode);

        $filename = "quotation-{$quotation->quotation_number}.pdf";

        if ($request->boolean('download')) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    /**
     * Print/Preview payment receipt.
     */
    public function paymentReceipt(Request $request, int $id): Response|JsonResponse
    {
        $payment = PaymentReceived::where('organization_id', $request->user()->organization_id)
            ->with(['allocations.invoice', 'customer', 'bankAccount'])
            ->findOrFail($id);

        $paperSize = $request->get('paper_size', 'a4');
        $templateCode = $request->get('template');
        $format = $request->get('format', 'pdf');

        if ($format === 'thermal') {
            $printerType = $request->get('printer_type');
            $data = $this->printService->generateThermalData('payment_receipt', $payment, $printerType);
            return response()->json(['data' => $data->toArray()]);
        }

        if ($format === 'html') {
            $html = $this->printService->generateHtml('payment_receipt', $payment, $paperSize, $templateCode);
            return response($html)->header('Content-Type', 'text/html');
        }

        $pdf = $this->printService->generatePdf('payment_receipt', $payment, $paperSize, $templateCode);

        $filename = "receipt-{$payment->payment_number}.pdf";

        if ($request->boolean('download')) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    /**
     * Print/Preview purchase order.
     */
    public function purchaseOrder(Request $request, int $id): Response|JsonResponse
    {
        $po = PurchaseOrder::where('organization_id', $request->user()->organization_id)
            ->with(['lines.product', 'lines.unit', 'supplier'])
            ->findOrFail($id);

        $paperSize = $request->get('paper_size', 'a4');
        $templateCode = $request->get('template');
        $format = $request->get('format', 'pdf');

        if ($format === 'html') {
            $html = $this->printService->generateHtml('purchase_order', $po, $paperSize, $templateCode);
            return response($html)->header('Content-Type', 'text/html');
        }

        $pdf = $this->printService->generatePdf('purchase_order', $po, $paperSize, $templateCode);

        $filename = "po-{$po->po_number}.pdf";

        if ($request->boolean('download')) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    /**
     * Batch print multiple documents.
     */
    public function batch(Request $request): Response|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_type' => 'required|string|in:invoice,quotation,purchase_order,payment_receipt',
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'required|integer',
            'paper_size' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $documentType = $request->get('document_type');
        $ids = $request->get('ids');
        $paperSize = $request->get('paper_size', 'a4');

        $modelClass = match ($documentType) {
            'invoice' => Invoice::class,
            'quotation' => Quotation::class,
            'purchase_order' => PurchaseOrder::class,
            'payment_receipt' => PaymentReceived::class,
        };

        $documents = $modelClass::where('organization_id', $request->user()->organization_id)
            ->whereIn('id', $ids)
            ->get();

        if ($documents->isEmpty()) {
            return response()->json(['error' => 'No documents found'], 404);
        }

        $pdf = $this->printService->generateBatchPdf($documentType, $documents->all(), $paperSize);

        $filename = "{$documentType}-batch-" . now()->format('Ymd-His') . ".pdf";

        if ($request->boolean('download')) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    /**
     * Initialize default templates for organization.
     */
    public function initializeDefaults(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;

        $existingCodes = PrintTemplate::where('organization_id', $organizationId)
            ->pluck('code')
            ->toArray();

        $created = 0;

        foreach (PrintTemplate::DEFAULT_TEMPLATES as $template) {
            if (!in_array($template['code'], $existingCodes)) {
                PrintTemplate::create(array_merge($template, [
                    'organization_id' => $organizationId,
                    'is_default' => $created === 0, // First one is default
                ]));
                $created++;
            }
        }

        return response()->json([
            'message' => "Created {$created} default templates",
            'created' => $created,
        ]);
    }
}
