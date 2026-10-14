<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\SubmissionTracker;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Console\Command;
use Throwable;

/**
 * File two invoices with ZATCA's sandbox the way production files them.
 *
 * fatoora:onboard --step=submit proves the authority accepts what the CLI
 * builds, signs and sends with its own HTTP calls. None of that is the code a
 * taxpayer's invoice goes through: Submitter issuing it, CredentialStore
 * finding the device, FatooraClient sending it, SubmissionLedger and
 * InvoiceVerdict recording the answer. So the platform's own clearance and
 * reporting path had never been put in front of the authority.
 *
 * This takes the production certificate the onboarding step just obtained,
 * stores it the way API onboarding stores it, and files a simplified and a
 * standard invoice through that path. It fails unless both are accepted and
 * the standard one comes back with ZATCA's stamped copy.
 *
 * Sandbox only. It creates a taxpayer and invoices in whatever database it
 * runs against.
 */
class FatooraSandboxFiling extends Command
{
    protected $signature = 'fatoora:sandbox-filing';

    protected $description = 'File a simplified and a standard invoice with the ZATCA sandbox through the platform\'s own path';

    /** The taxpayer the sandbox certificate is issued for - see fatoora:generate-csr. */
    private const VAT_NUMBER = '399999999900003';

    public function handle(Submitter $submitter, SubmissionTracker $tracker, CredentialStore $credentials): int
    {
        if (config('fatoora.environment') !== 'sandbox') {
            $this->error('Refusing to run: ZATCA_ENVIRONMENT is "'.config('fatoora.environment').'". This files real documents and is for the sandbox only.');

            return self::FAILURE;
        }

        $device = $this->onboardedDevice();

        if ($device === null) {
            $this->error('No production certificate in storage/app/zatca. Run fatoora:onboard --step=full --target=sandbox first.');

            return self::FAILURE;
        }

        $taxpayer = $this->taxpayer();
        $credentials->put((string) $taxpayer->id, null, CredentialStore::PCSID, $device);

        $results = [];
        $failed = false;

        foreach (['simplified', 'standard'] as $type) {
            try {
                $invoice = $this->draft($taxpayer, $type);
                $submitter->generate($invoice, $taxpayer);
                $tracker->submit($invoice->fresh(['lines']));
                $invoice = $invoice->fresh();

                $accepted = $invoice->status === InvoiceStatus::Accepted;
                $stamped = $type === 'simplified' || $invoice->cleared_xml !== null;
                $failed = $failed || ! $accepted || ! $stamped;

                $results[] = [
                    $type,
                    $invoice->invoice_number,
                    $invoice->status->value,
                    $type === 'standard' ? ($stamped ? 'kept' : 'MISSING') : 'n/a',
                    $this->reason($invoice),
                ];
            } catch (Throwable $e) {
                $failed = true;
                $results[] = [$type, '-', 'ERROR', '-', $e->getMessage()];
            }
        }

        $this->table(['Type', 'Invoice', 'Status', 'Stamped copy', 'Authority said'], $results);

        if ($failed) {
            $this->error('FAILED: the platform\'s own filing path did not get both invoices accepted.');

            return self::FAILURE;
        }

        $this->info('Both invoices were accepted through the platform\'s own filing path.');

        return self::SUCCESS;
    }

    /**
     * What fatoora:onboard left behind, in the shape API onboarding stores.
     *
     * @return array{pcsid: string, secret: string, privateKey: string}|null
     */
    private function onboardedDevice(): ?array
    {
        $dir = storage_path('app/zatca');
        $files = [
            'pcsid' => $dir.'/pcsid_token.txt',
            'secret' => $dir.'/pcsid_secret.txt',
            'privateKey' => $dir.'/ccsid_private_key.pem',
        ];

        $device = [];

        foreach ($files as $key => $path) {
            $value = is_file($path) ? trim((string) file_get_contents($path)) : '';

            if ($value === '') {
                return null;
            }

            $device[$key] = $value;
        }

        return $device;
    }

    private function taxpayer(): Organization
    {
        return Organization::query()->firstOrCreate(['vat_number' => self::VAT_NUMBER], [
            'name' => 'Maximum Speed Tech Supply LTD',
            'country' => 'SA',
            'cr_number' => '1010010000',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
    }

    private function draft(Organization $taxpayer, string $type): Invoice
    {
        $standard = $type === 'standard';

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $taxpayer->id,
            'invoice_number' => 'APP-'.strtoupper($type).'-'.date('YmdHis'),
            'type' => $type,
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => $standard ? 'Fatoora Samples LTD' : null,
            'buyer_vat_number' => $standard ? '399999999800003' : null,
            'buyer_address' => $standard ? [
                'street' => 'Salah Al-Din',
                'building_number' => '1111',
                'district' => 'Al-Murooj',
                'city' => 'Riyadh',
                'postal_code' => '12222',
                'country_code' => 'SA',
            ] : null,
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Sandbox filing check',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    private function reason(Invoice $invoice): string
    {
        $response = (array) ($invoice->zatca_response ?? []);
        $messages = [...(array) ($response['errors'] ?? []), ...(array) ($response['warnings'] ?? [])];

        $text = implode('; ', array_map(
            fn (mixed $m): string => is_array($m) ? (string) ($m['message'] ?? json_encode($m)) : (string) $m,
            $messages
        ));

        return $text !== '' ? mb_strimwidth($text, 0, 160, '...') : (string) ($response['reporting_status'] ?? $response['clearance_status'] ?? '-');
    }
}
