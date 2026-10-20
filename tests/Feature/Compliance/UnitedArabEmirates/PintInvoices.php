<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Models\ProviderAppointment;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;

/**
 * A UAE organization and its invoices, as the PINT tests need them.
 *
 * The identifiers are shaped as the schematron wants them - a TRN is 15
 * digits starting 1 and ending 03, a TIN its first ten - and are not real.
 *
 * By default it is e-invoicing already - from the voluntary start, 1 July
 * 2026 (Guidelines v1.1, 8.2) - with its service provider appointed, so its
 * invoices go to the provider. ['einvoicing_start' => null] is one before
 * its start date; appointed: false one with no provider recorded.
 */
trait PintInvoices
{
    /** @param  array<string, mixed>  $settings */
    private function uaeOrganization(array $settings = [], bool $appointed = true): Organization
    {
        $organization = Organization::create([
            'name' => 'Gulf Trading LLC',
            'country' => 'AE',
            'street' => 'Sheikh Zayed Road',
            'city' => 'Dubai',
        ]);

        ComplianceProfile::create([
            'org_id' => $organization->id,
            'jurisdiction' => 'AE',
            'engine' => 'peppol',
            'status' => ComplianceProfile::STATUS_ACTIVE,
            'settings' => array_merge([
                'trn' => '100123456700003',
                'tin' => '1001234567',
                'endpoint_id' => '0235:1001234567',
                'legal_registration_type' => 'TL',
                'legal_registration_id' => 'CN-1234567',
                'legal_registration_authority' => 'Dubai Economy and Tourism',
                'emirate' => 'DXB',
                'payment_account' => 'AE070331234567890123456',
                'einvoicing_start' => '2026-07-01',
            ], $settings),
        ]);

        if ($appointed) {
            ProviderAppointment::withoutTenantScope(fn () => ProviderAppointment::query()->create([
                'org_id' => $organization->id,
                'provider' => 'platform',
                'status' => ProviderAppointment::APPOINTED,
                'appointed_at' => '2026-06-15',
            ]));
        }

        return $organization;
    }

    /**
     * An issued invoice: created as a draft, given its lines, then issued,
     * since a line cannot be added to an issued invoice.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $lines
     * @param  array<string, mixed>  $data  jurisdiction_data, over the defaults
     */
    private function uaeInvoice(Organization $organization, array $attributes = [], ?array $lines = null, array $data = []): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create(array_merge([
            'org_id' => $organization->id,
            'invoice_number' => 'AE-'.substr(uniqid(), -8),
            'type' => 'standard',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => '2026-10-10',
            'due_date' => '2026-11-09',
            'currency' => 'AED',
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi', 'country_code' => 'AE'],
            'jurisdiction_data' => array_merge([
                'transaction_flags' => '00000000',
                'buyer_endpoint' => '0235:1007654321',
                'buyer_subdivision' => 'AUH',
            ], $data),
        ], $attributes)));

        foreach ($lines ?? [$this->uaeLine()] as $line) {
            InvoiceLine::create(array_merge(['invoice_id' => $invoice->id], $line));
        }

        $invoice->update(['status' => 'issued']);

        return Invoice::withoutTenantScope(fn () => $invoice->fresh(['lines', 'org']));
    }

    /**
     * A standard-rated line: two days' consulting at 500.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function uaeLine(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Consulting services, October',
            'item_name' => 'Consulting',
            'quantity' => 2,
            'unit_code' => 'DAY',
            'unit_price' => 500,
            'net_amount' => 1000,
            'tax_rate' => 5,
            'tax_amount' => 50,
            'tax_category' => 'S',
            'line_total' => 1050,
        ], $overrides);
    }
}
