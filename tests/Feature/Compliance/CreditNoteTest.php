<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A credit note undoes part of an invoice, and ZATCA will not take one that
 * does not say which invoice or why.
 *
 * BR-KSA-17 requires the reason, and the reference to the original document is
 * what ties the correction to the thing corrected — without it the note is a
 * negative invoice from nowhere and the taxpayer's ledger cannot be reconciled
 * against what was filed.
 */
class CreditNoteTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private Organization $organization;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $user = User::factory()->create(['email' => 'biller@masaar.test']);
        $user->organizations()->attach($this->organization->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'biller@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    public function test_a_credit_note_is_typed_381(): void
    {
        $xpath = $this->creditNote();

        $this->assertSame('381', $this->one($xpath, '/*/cbc:InvoiceTypeCode'));
    }

    public function test_it_names_the_invoice_it_corrects(): void
    {
        $xpath = $this->creditNote(reference: 'INV-ORIGINAL-1');

        $this->assertSame(
            'INV-ORIGINAL-1',
            $this->one($xpath, '/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'),
            'The credit note does not reference the invoice it corrects.'
        );
    }

    /**
     * The reason is collected by the API, stored on the invoice, and was never
     * carried into the document — the parameter existed and nothing passed it.
     */
    public function test_it_carries_the_reason(): void
    {
        $xpath = $this->creditNote(reason: 'Goods returned damaged');

        $this->assertSame(
            'Goods returned damaged',
            $this->one($xpath, '/*/cbc:Note'),
            'The credit note gives no reason (BR-KSA-17).'
        );
    }

    /**
     * An ordinary invoice has nothing to explain, so it carries no note and no
     * reference — a Note appearing there would be a field filled for its own
     * sake.
     */
    public function test_a_plain_invoice_has_neither(): void
    {
        $xpath = $this->document([
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer Co',
            'lines' => [$this->line()],
        ]);

        $this->assertNull($this->one($xpath, '/*/cbc:Note'));
        $this->assertNull($this->one($xpath, '/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'));
    }

    private function creditNote(
        string $reference = 'INV-ORIGINAL-1',
        string $reason = 'Partial return',
    ): DOMXPath {
        return $this->document([
            'invoice_number' => 'CN-'.uniqid(),
            'type' => 'simplified',
            'document_type' => 'credit_note',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer Co',
            'billing_ref' => $reference,
            'adjustment_reason' => $reason,
            'lines' => [$this->line()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(): array
    {
        return [
            'description' => 'Item',
            'quantity' => 1,
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_category' => 'S',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function document(array $payload): DOMXPath
    {
        $id = $this->withToken($this->token)
            ->postJson('/api/invoices', $payload)
            ->assertSuccessful()
            ->json('data.invoice.id');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));

        $credentials = $this->selfSignedCredentials();

        $xml = app(DocumentBuilder::class)->generateComplianceData(
            invoice: $invoice,
            organization: $this->organization,
            previousInvoiceHash: null,
            privateKey: $credentials['privateKey'],
            certificate: $credentials['certificate'],
        )['xml'];

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::CBC);
        $xpath->registerNamespace('cac', self::CAC);

        return $xpath;
    }

    private function one(DOMXPath $xpath, string $query): ?string
    {
        return $xpath->query($query)->item(0)?->textContent;
    }
}
