<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Models\Product;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * A printed invoice is the one issued: the item code a line prints is the
 * one its product had when the invoice was issued, kept on the line
 * (LineSnapshot), whatever becomes of the product after.
 */
class LineSnapshotTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
        app(InvoiceTemplates::class)->save(Level::Organization, Scope::organization((string) $this->organization->id), ['column_item_code' => true], $this->organization, null);
    }

    public function test_editing_the_product_changes_no_print(): void
    {
        $product = $this->product('COF-500');
        $invoice = $this->issuedWith($product);

        $this->assertSame('COF-500', InvoiceLine::query()->where('invoice_id', $invoice->id)->value('item_code'));

        $product->update(['sku' => 'COF-501']);
        $product->delete();
        $member = User::factory()->create();
        $member->organizations()->attach($this->organization->id, ['role' => 'member', 'status' => 'active']);

        foreach ([PaperSize::A4, PaperSize::A5] as $paper) {
            $pdf = app(InvoicePdf::class)->html($invoice->fresh(['lines']), $this->organization, $paper);
            $view = (string) $this->actingAs($member)->withSession(['portal_org_id' => $this->organization->id])
                ->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))->assertOk()->getContent();

            foreach ([$pdf, $view] as $html) {
                $this->assertStringContainsString('COF-500', $html);
                $this->assertStringNotContainsString('COF-501', $html);
            }
        }
    }

    /** A draft keeps none until it is issued: it may still change. */
    public function test_a_draft_takes_none_yet(): void
    {
        $invoice = $this->draftWith($this->product('COF-500'));

        $this->assertNull(InvoiceLine::query()->where('invoice_id', $invoice->id)->value('item_code'));
    }

    /**
     * Invoices issued before the snapshot: the backfill copies today's code,
     * best effort, and leaves drafts and copies already taken alone.
     */
    public function test_the_backfill_copies_todays_code(): void
    {
        $issued = $this->issuedWith($this->product('OLD-1'));
        $taken = $this->issuedWith($this->product('KEPT-1'));
        $draft = $this->draftWith($this->product('DRAFT-1'));
        DB::table('invoice_lines')->where('invoice_id', $issued->id)->update(['item_code' => null]);
        DB::table('invoice_lines')->where('invoice_id', $taken->id)->update(['item_code' => 'AS-ISSUED']);

        (require database_path('migrations/1240_backfill_invoice_line_item_codes.php'))->up();

        $this->assertSame('OLD-1', DB::table('invoice_lines')->where('invoice_id', $issued->id)->value('item_code'));
        $this->assertSame('AS-ISSUED', DB::table('invoice_lines')->where('invoice_id', $taken->id)->value('item_code'));
        $this->assertNull(DB::table('invoice_lines')->where('invoice_id', $draft->id)->value('item_code'));
    }

    private function product(string $sku): Product
    {
        return Product::withoutTenantScope(fn () => Product::query()->create([
            'org_id' => $this->organization->id, 'name' => 'Arabic coffee '.$sku, 'name_ar' => 'قهوة عربية', 'sku' => $sku,
            'unit_price' => '42.50', 'unit_code' => 'C62', 'tax_rate' => '15',
        ]));
    }

    private function draftWith(Product $product): Invoice
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 1);
        // Back to a draft, with a line made from the product: as one is
        // before it is issued.
        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)->toBase()->update(['status' => 'draft']));
        InvoiceLine::query()->where('invoice_id', $invoice->id)->toBase()->update(['product_id' => $product->id, 'item_code' => null]);

        return Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->findOrFail($invoice->id));
    }

    private function issuedWith(Product $product): Invoice
    {
        $invoice = $this->draftWith($product);
        $invoice->update(['status' => 'issued']);

        return $invoice->fresh(['lines']);
    }
}
