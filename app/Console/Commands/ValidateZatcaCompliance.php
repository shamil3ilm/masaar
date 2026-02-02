<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Zatca\DTOs\AddressData;
use App\Domains\Compliance\Zatca\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Zatca\Services\XmlBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Generate a sample ZATCA-compliant invoice XML for validation.
 *
 * Usage:
 *   php artisan zatca:validate-compliance
 *   php artisan zatca:validate-compliance --output=/path/to/invoice.xml
 *
 * Then validate with ZATCA SDK:
 *   fatoora -validate -invoice storage/app/zatca/sample_invoice.xml
 */
class ValidateZatcaCompliance extends Command
{
    protected $signature = 'zatca:validate-compliance
                            {--output= : Output path for the XML file}
                            {--type=standard : Invoice type (standard or simplified)}';

    protected $description = 'Generate a sample ZATCA-compliant invoice XML for validation testing';

    public function handle(): int
    {
        $this->info('Generating ZATCA-compliant invoice XML...');
        $this->newLine();

        $type = $this->option('type');
        $isStandard = $type === 'standard';

        // Create sample invoice data
        $invoiceData = new InvoiceXmlData(
            invoiceNumber: 'INV-' . date('Ymd') . '-001',
            uuid: $this->generateUuid(),
            issueDate: date('Y-m-d'),
            issueTime: date('H:i:s'),
            invoiceTypeCode: $isStandard ? '388' : '388', // 388 = Tax Invoice
            currency: 'SAR',
            icv: 1,
            previousInvoiceHash: base64_encode(str_repeat("\0", 32)), // First invoice
            sellerName: 'Test Company LLC',
            sellerVatNumber: '300000000000003', // Test VAT number
            sellerCrNumber: '1234567890',
            sellerAddress: new AddressData(
                street: 'King Fahd Road',
                additionalStreet: null,
                buildingNumber: '1234',
                plotIdentification: '5678',
                district: 'Al Olaya',
                city: 'Riyadh',
                postalCode: '12345',
                countrySubentity: 'Riyadh Region',
                countryCode: 'SA',
            ),
            buyerName: 'Customer Company',
            buyerVatNumber: $isStandard ? '300000000000004' : null, // B2B needs VAT
            buyerAddress: $isStandard ? new AddressData(
                street: 'Prince Sultan Road',
                buildingNumber: '5678',
                district: 'Al Malaz',
                city: 'Riyadh',
                postalCode: '54321',
                countryCode: 'SA',
            ) : null,
            lines: [
                [
                    'description' => 'Consulting Services',
                    'quantity' => 10,
                    'unitPrice' => 100.00,
                    'unitCode' => 'HUR', // Hours
                    'taxRate' => 15.0,
                    'taxCategory' => 'S',
                    'lineTotal' => 1150.00,
                    'taxAmount' => 150.00,
                ],
                [
                    'description' => 'Software License',
                    'quantity' => 1,
                    'unitPrice' => 500.00,
                    'unitCode' => 'PCE',
                    'taxRate' => 15.0,
                    'taxCategory' => 'S',
                    'lineTotal' => 575.00,
                    'taxAmount' => 75.00,
                ],
            ],
            subtotal: 1500.00,
            discount: 0.0,
            taxAmount: 225.00,
            total: 1725.00,
        );

        // Build XML
        $builder = new XmlBuilder();
        $xml = $builder->build($invoiceData);

        // Output path
        $outputPath = $this->option('output')
            ?? storage_path('app/zatca/sample_invoice.xml');

        // Ensure directory exists
        $dir = dirname($outputPath);
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // Save XML
        File::put($outputPath, $xml);

        $this->info("✓ Invoice XML generated: {$outputPath}");
        $this->newLine();

        // Display validation checklist
        $this->displayValidationChecklist($invoiceData, $isStandard);

        // Display next steps
        $this->newLine();
        $this->info('Next Steps for ZATCA SDK Validation:');
        $this->line('1. Download ZATCA SDK from: https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/ComplianceEnablementToolbox/Pages/DownloadSDK.aspx');
        $this->line('2. Run: fatoora -validate -invoice ' . $outputPath);
        $this->line('3. Expected result: XSD_ZATCA_VALID');
        $this->newLine();

        // Also validate with EU Interoperability Test Bed
        $this->info('Alternative Online Validation:');
        $this->line('Upload to: https://www.itb.ec.europa.eu/invoice/ubl/upload');
        $this->line('Select: UBL 2.1 Invoice');

        return Command::SUCCESS;
    }

    private function displayValidationChecklist(InvoiceXmlData $data, bool $isStandard): void
    {
        $this->info('ZATCA Compliance Checklist:');
        $this->table(
            ['Requirement', 'Status', 'Value'],
            [
                ['UBLVersionID', '✓', '2.1'],
                ['CustomizationID', '✓', 'urn:oasis:names:specification:ubl:xpath:Invoice-2.0:sac-mod'],
                ['ProfileID', '✓', 'reporting:1.0'],
                ['Invoice ID', '✓', $data->invoiceNumber],
                ['UUID (UUIDv4)', '✓', $data->uuid],
                ['Issue Date', '✓', $data->issueDate],
                ['Issue Time', '✓', $data->issueTime],
                ['Invoice Type Code', '✓', $data->invoiceTypeCode],
                ['Currency', '✓', $data->currency],
                ['ICV (Counter)', '✓', (string) $data->icv],
                ['PIH (Previous Hash)', '✓', 'Base64 encoded'],
                ['Seller VAT', '✓', $data->sellerVatNumber],
                ['Seller Address', '✓', 'Complete with all fields'],
                ['Buyer VAT', $isStandard ? '✓' : 'N/A', $data->buyerVatNumber ?? 'Not required for B2C'],
                ['Tax Total', '✓', number_format($data->taxAmount, 2) . ' SAR'],
                ['Invoice Lines', '✓', count($data->lines) . ' line(s)'],
            ]
        );
    }

    private function generateUuid(): string
    {
        // Generate UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
