<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Invoice\Models\InvoiceAdjustment;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Return, exchange or replace on a UAE invoice: a Tax Credit Note with the
 * Decree-Law's reason code (Art 61(1), BTAE-03), and an increase invoiced,
 * since the UAE has no debit note (Art 62(1)).
 *
 * The original: two days' consulting at 500 = 1,000.00 + VAT 5% 50.00.
 */
class UaeAdjustmentTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        $this->gulf = $this->uaeOrganization();
        License::create([
            'org_id' => $this->gulf->id, 'api_key' => 'cp_test_uae_adjust', 'api_secret_hash' => License::hashSecret('secret-uae'),
            'organization_name' => 'Gulf', 'contact_email' => 'ops@gulf.test', 'environment' => 'sandbox', 'tier' => 'enterprise',
            'status' => 'active', 'scopes' => ['invoice.read', 'invoice.submit'],
        ]);
    }

    /** One day back: a tax credit note for 500 + 25 = 525.00, reason D (goods or services returned). */
    public function test_return_plans_tax_credit_note(): void
    {
        $preview = $this->preview(['kind' => 'partial_return', 'lines' => [['line' => 1, 'quantity' => 1]]]);

        $this->assertSame(['credit', 'credit_note', '525.00'], [$preview['documents'][0]['role'], $preview['documents'][0]['document_type'], $preview['documents'][0]['total']]);
        $this->assertSame('DL8.61.1.D', $preview['reason']);
        $this->assertSame(0, InvoiceAdjustment::query()->count());
    }

    /** A day at 500 replaced by one at 700: a new tax invoice for 200 + 10 = 210.00, no debit note. */
    public function test_increase_is_a_new_invoice(): void
    {
        $preview = $this->preview(['kind' => 'price_difference', 'lines' => [['line' => 1, 'quantity' => 1]], 'items' => [['description' => 'Senior consulting', 'quantity' => 1, 'unit_price' => 700]]]);

        $this->assertSame(['increase', 'invoice', '210.00'], [$preview['documents'][0]['role'], $preview['documents'][0]['document_type'], $preview['documents'][0]['total']]);
    }

    /** @return array<string, mixed> */
    private function preview(array $body): array
    {
        $invoice = $this->uaeInvoice($this->gulf, ['subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00']);
        $response = $this->withHeaders(['X-API-Key' => 'cp_test_uae_adjust', 'X-API-Secret' => 'secret-uae'])
            ->postJson("/api/v1/invoices/{$invoice->id}/adjustments", [...$body, 'preview' => true]);
        $this->assertSame(200, $response->status(), (string) $response->getContent());

        return $response->json('data.preview');
    }
}
