<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Auth\Models\User;
use App\Domains\Books\DTOs\PurchaseEntry;
use App\Domains\Books\DTOs\PurchaseLineEntry;
use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\PurchaseInvoice;
use App\Domains\Books\Models\Supplier;
use App\Domains\Books\Services\Purchases;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * An organization with suppliers, purchases and issued sales, for the Books
 * tests: amounts are typed as an accountant would, so every figure a test
 * checks can be worked by hand.
 */
trait BooksFixtures
{
    private int $sales = 0;

    private function saudi(string $name = 'Acme Trading'): Organization
    {
        return Organization::create(['name' => $name, 'country' => 'SA', 'vat_number' => '300000000000003', 'status' => 'active']);
    }

    private function supplier(Organization $organization, array $values = []): Supplier
    {
        return Supplier::withoutTenantScope(fn () => Supplier::query()->create([
            'org_id' => $organization->id, 'name' => 'Riyadh Office Supplies', 'vat_number' => '310000000000003', 'country_code' => 'SA', ...$values,
        ]));
    }

    /**
     * @param  list<array{0: PurchaseTreatment, 1: string, 2?: string|null, 3?: bool}>  $lines  treatment, net, vat, capital asset
     */
    private function purchase(Organization $organization, Supplier $supplier, array $lines, array $options = []): PurchaseInvoice
    {
        return app(Purchases::class)->record($organization, new PurchaseEntry(
            supplierId: (string) $supplier->id,
            supplierInvoiceNumber: $options['number'] ?? 'SUP-'.uniqid(),
            invoiceDate: CarbonImmutable::parse($options['date'] ?? now()->toDateString()),
            currency: $options['currency'] ?? 'SAR',
            lines: array_map(fn (array $line): PurchaseLineEntry => new PurchaseLineEntry(
                description: 'Goods',
                treatment: $line[0],
                net: $line[1],
                vat: $line[2] ?? null,
                capitalAsset: $line[3] ?? false,
            ), $lines),
            exchangeRate: $options['rate'] ?? null,
            deductibleShare: $options['share'] ?? '100',
            documentType: $options['type'] ?? 'invoice',
            originalId: $options['original'] ?? null,
            receivedOn: isset($options['received']) ? CarbonImmutable::parse($options['received']) : null,
            dueOn: isset($options['due']) ? CarbonImmutable::parse($options['due']) : null,
            paidLater: $options['paid_later'] ?? false,
        ));
    }

    /**
     * An issued sale with one line per [net, rate, category, exempt code].
     *
     * @param  list<array{0: float, 1: float, 2?: string, 3?: string|null}>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function sale(Organization $organization, array $lines, array $overrides = []): Invoice
    {
        $this->sales++;
        $net = array_sum(array_column($lines, 0));
        $tax = array_sum(array_map(fn (array $line): float => round($line[0] * $line[1] / 100, 2), $lines));
        $type = $overrides['document_type'] ?? 'invoice';
        $note = in_array($type, ['credit_note', 'debit_note'], true);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->create([
            'org_id' => $organization->id,
            'invoice_number' => $overrides['invoice_number'] ?? 'INV-'.$this->sales,
            'type' => $overrides['type'] ?? 'standard',
            'payment_means_code' => $overrides['payment_means_code'] ?? null,
            'document_type' => $type,
            'billing_ref' => $note ? ($overrides['billing_ref'] ?? null) : null,
            'adjustment_reason' => $note ? 'Returned goods' : null,
            'status' => 'draft',
            'issue_date' => $overrides['issue_date'] ?? now()->toDateString(),
            'due_date' => $overrides['due_date'] ?? null,
            'currency' => $overrides['currency'] ?? 'SAR',
            'exchange_rate' => $overrides['exchange_rate'] ?? null,
            'buyer_name' => $overrides['buyer_name'] ?? 'Gulf Supplies',
            'buyer_vat_number' => $overrides['buyer_vat_number'] ?? null,
            'branch_id' => $overrides['branch_id'] ?? null,
            'jurisdiction_data' => $overrides['jurisdiction_data'] ?? null,
            'buyer_address' => $overrides['buyer_address'] ?? null,
            'subtotal' => number_format($net, 2, '.', ''),
            'tax_amount' => number_format($tax, 2, '.', ''),
            'total' => number_format($net + $tax, 2, '.', ''),
        ]));

        foreach ($lines as $index => $line) {
            $lineTax = round($line[0] * $line[1] / 100, 2);
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => $overrides['item'] ?? 'Item',
                'item_name' => $overrides['item'] ?? 'Item',
                'product_id' => $overrides['product_id'] ?? null,
                'quantity' => '1',
                'unit_price' => number_format($line[0], 2, '.', ''),
                'net_amount' => number_format($line[0], 2, '.', ''),
                'tax_rate' => (string) $line[1],
                'tax_amount' => number_format($lineTax, 2, '.', ''),
                'tax_category' => $line[2] ?? 'S',
                'exempt_code' => $line[3] ?? null,
                'exempt_reason' => isset($line[3]) ? 'Reason' : null,
                'line_total' => number_format($line[0] + $lineTax, 2, '.', ''),
            ]);
        }

        $invoice->forceFill([
            'status' => $overrides['status'] ?? 'issued',
            'icv' => $this->sales,
            'hash' => base64_encode(hash('sha256', 'sale'.$this->sales.$organization->id, true)),
            'qr_code' => base64_encode('AQVTZWxsZXI='),
            ...(isset($overrides['customer_id']) ? ['customer_id' => $overrides['customer_id']] : []),
        ])->save();

        return $invoice;
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function as(Organization $organization, string $role): static
    {
        return $this->actingAs($this->member($organization, $role))->withSession(['portal_org_id' => $organization->id]);
    }
}
