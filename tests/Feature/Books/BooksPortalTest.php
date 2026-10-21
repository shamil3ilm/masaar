<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Books\Models\PurchaseInvoice;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Books in the portal: who may read and record, the forms, the files, and
 * the feature switch - each through a real request.
 */
class BooksPortalTest extends TestCase
{
    use BooksFixtures;
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->saudi();
    }

    public function test_accountant_records_a_purchase(): void
    {
        Storage::fake('receipts');
        $supplier = $this->supplier($this->acme);

        $response = $this->as($this->acme, 'accountant')->post(route('portal.books.purchases.store'), [
            'supplier_id' => $supplier->id, 'supplier_invoice_number' => 'RS-9', 'invoice_date' => now()->toDateString(),
            'document_type' => 'invoice', 'currency' => 'SAR', 'deductible_share' => '100',
            'lines' => [['description' => 'Paper', 'treatment' => 'standard', 'net' => '200'], ['description' => '', 'net' => '']],
            'file' => UploadedFile::fake()->createWithContent('rs-9.pdf', "%PDF-1.4\n%test\n"),
        ]);

        $purchase = PurchaseInvoice::withoutTenantScope(fn () => PurchaseInvoice::query()->sole());
        $response->assertRedirect(route('portal.books.purchases.show', $purchase->id));
        $this->assertSame(['230.00', '30.00', 'portal'], [$purchase->gross_tax, $purchase->deductible_vat_tax, $purchase->created_via]);
        $this->assertNotNull($purchase->file_path);
    }

    public function test_viewer_reads_but_cannot_record(): void
    {
        $supplier = $this->supplier($this->acme);
        $this->purchase($this->acme, $supplier, [[PurchaseTreatment::Standard, '100']], ['number' => 'RS-1']);

        $this->asRequest(fn () => $this->as($this->acme, 'viewer')->get(route('portal.books.purchases.index'))->assertOk()->assertSee('RS-1')->assertDontSee(__('Record a purchase')));
        $this->as($this->acme, 'viewer')->post(route('portal.books.suppliers.store'), ['name' => 'X'])->assertForbidden();
    }

    public function test_switched_off_books_are_refused(): void
    {
        app(FeatureOverrides::class)->set((string) $this->acme->id, Feature::Books, 'off', 'Not for this company', null);

        $this->asRequest(fn () => $this->as($this->acme, 'admin')->get(route('portal.books.index'))->assertForbidden());
        $this->asRequest(fn () => $this->as($this->acme, 'admin')->get(route('portal.dashboard'))->assertOk()->assertDontSee(route('portal.books.index')));
    }

    public function test_another_companys_purchase_is_not_found(): void
    {
        $other = $this->saudi('Other Co');
        $theirs = $this->purchase($other, $this->supplier($other), [[PurchaseTreatment::Standard, '100']]);

        $this->asRequest(fn () => $this->as($this->acme, 'admin')->get(route('portal.books.purchases.show', $theirs->id))->assertNotFound());
    }

    /** Two rows of one invoice and one of another: two purchases, a supplier added. */
    public function test_csv_import_records_grouped_rows(): void
    {
        $csv = "supplier_name,supplier_vat_number,invoice_number,invoice_date,description,treatment,net,vat,capital_asset\n"
            ."Riyadh Office Supplies,310000000000003,RS-1,2026-10-05,Paper,standard,1000,150,no\n"
            ."Riyadh Office Supplies,310000000000003,RS-1,2026-10-05,Laptop,standard,4000,600,yes\n"
            ."Jeddah Water,,JW-7,2026-10-06,Water,exempt,50,,no\n";

        $this->as($this->acme, 'member')->post(route('portal.books.purchases.import'), [
            'file' => UploadedFile::fake()->createWithContent('purchases.csv', $csv),
        ])->assertRedirect(route('portal.books.purchases.index'))->assertSessionHas('success', 'Purchases imported: 2.');

        $first = PurchaseInvoice::withoutTenantScope(fn () => PurchaseInvoice::query()->where('supplier_invoice_number', 'RS-1')->with('lines')->sole());
        $this->assertSame(['5750.00', '750.00', 'import'], [$first->gross_tax, $first->deductible_vat_tax, $first->created_via]);
        $this->assertTrue($first->lines[1]->is_capital_asset);
    }

    public function test_bad_csv_row_imports_nothing(): void
    {
        $csv = "supplier_name,invoice_number,invoice_date,description,treatment,net\n"
            ."A,1,2026-10-05,Paper,standard,100\n"
            ."B,2,2026-10-05,Paper,sideways,100\n";

        $this->as($this->acme, 'member')->post(route('portal.books.purchases.import'), [
            'file' => UploadedFile::fake()->createWithContent('purchases.csv', $csv),
        ])->assertSessionHas('error');

        $this->assertSame(0, PurchaseInvoice::withoutTenantScope(fn () => PurchaseInvoice::query()->count()));
    }

    public function test_payment_recorded_from_the_form(): void
    {
        $invoice = $this->sale($this->acme, [[1000, 15]]);

        $this->as($this->acme, 'accountant')->post(route('portal.books.invoices.pay', $invoice->id), [
            'amount' => '500', 'paid_on' => now()->toDateString(), 'method' => 'cash',
        ])->assertRedirect(route('portal.books.invoices.show', $invoice->id));

        $this->asRequest(fn () => $this->as($this->acme, 'viewer')->get(route('portal.books.invoices.show', $invoice->id))->assertOk()->assertSee('650.00'));
        $this->assertSame('500.00', InvoicePayment::withoutTenantScope(fn () => InvoicePayment::query()->sole())->amount);
    }

    /** Box 1 is 1,000 / 150, and its link lists INV-1. */
    public function test_return_page_links_its_documents(): void
    {
        $this->sale($this->acme, [[1000, 15]]);

        $this->asRequest(fn () => $this->as($this->acme, 'viewer')->get(route('portal.books.vat-return'))->assertOk()
            ->assertSee('Standard rated sales')->assertSee('1,000.00')->assertSee(route('portal.books.vat-return.box', ['box' => '1', 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()])));
        $this->asRequest(fn () => $this->as($this->acme, 'viewer')->get(route('portal.books.vat-return.box', ['box' => '1']))->assertOk()->assertSee('INV-1'));

        $csv = $this->asRequest(fn () => $this->as($this->acme, 'viewer')->get(route('portal.books.vat-return.csv', ['corrections' => '10']))->assertOk()->streamedContent());
        $this->assertStringContainsString('1,"Standard rated sales",1000.00,0.00,150.00,1', $csv);
        $this->assertStringContainsString('16,"Net VAT due (or claimed)",,,160.00', $csv);
    }

    public function test_pdfs_download_in_arabic(): void
    {
        $customer = Customer::withoutTenantScope(fn () => Customer::query()->create(['org_id' => $this->acme->id, 'name' => 'Gulf Supplies', 'name_ar' => 'خليج', 'vat_number' => '310122393500003']));
        $this->sale($this->acme, [[1000, 15]], ['buyer_vat_number' => '310122393500003']);

        foreach ([route('portal.books.statements.pdf', $customer->id), route('portal.books.vat-return.pdf')] as $url) {
            $response = $this->asRequest(fn () => $this->as($this->acme, 'viewer')->withSession(['portal_org_id' => $this->acme->id, 'locale' => 'ar'])->get($url));
            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        }

        $this->asRequest(fn () => $this->as($this->acme, 'viewer')->get(route('portal.books.statements.show', $customer->id))->assertOk()->assertSee('1,150.00'));
    }

    public function test_sales_and_receivables_csvs(): void
    {
        $this->sale($this->acme, [[1000, 15]], ['buyer_name' => 'Gulf Supplies']);

        $sales = $this->asRequest(fn () => $this->as($this->acme, 'viewer')->get(route('portal.books.sales.csv', ['by' => 'customer']))->assertOk()->streamedContent());
        $this->assertStringContainsString('"Gulf Supplies",1,,1000.00,150.00,1150.00', $sales);

        $owed = $this->asRequest(fn () => $this->as($this->acme, 'viewer')->get(route('portal.books.receivables.csv'))->assertOk()->streamedContent());
        $this->assertStringContainsString('INV-1,"Gulf Supplies"', $owed);
        $this->assertStringContainsString('1150.00,0.00,0.00,1150.00', $owed);
    }
}
