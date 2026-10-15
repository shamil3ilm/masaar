<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Services\Connectivity;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Issuing an invoice from the portal.
 *
 * Issuance is the irreversible step: it allocates the counter, writes the hash
 * and records the chain entry. Everything here is about the two ways that goes
 * wrong - doing it twice, and doing it for an organization that cannot file.
 */
class InvoiceIssueTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'issue-token';

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        // The VAT number is tag 2 of the QR and DocumentBuilder refuses a
        // payload without it, so an organization with no registration cannot
        // issue at all - certificate or not. 15 digits opening and closing
        // with 3, as ZATCA requires.
        $this->acme = Organization::create([
            'name' => 'Acme',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            // BR-KSA-09: the seller's address is on every document, and an
            // invoice without one is refused before a counter is spent.
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, [
            'role' => 'member',
            'status' => 'active',
        ]);

        // Connectivity makes a real outbound HEAD request otherwise
        // (Connectivity.php:132-136, reached from shouldUseOfflineMode()).
        //
        // Only shouldUseOfflineMode() is expected. Connectivity has no
        // isOnline() - its predicate is isAvailable() - and Mockery accepts an
        // expectation on a method that does not exist, so mocking isOnline
        // would pass while documenting an API that is not there.
        $this->mock(Connectivity::class, function ($mock) {
            $mock->shouldReceive('shouldUseOfflineMode')->andReturn(false);
        });
    }

    /**
     * Phase 1: no certificate, and the invoice is still a real invoice.
     */
    public function test_issuing_needs_no_certificate(): void
    {
        $invoice = $this->draft('ACME-1');

        $this->issue($invoice)->assertRedirect(route('portal.invoices.show', $invoice->id));

        $fresh = $invoice->fresh();

        $this->assertSame(InvoiceStatus::Issued, $fresh->status);
        $this->assertNotNull($fresh->icv);
        $this->assertNotNull($fresh->hash);
        $this->assertNotNull($fresh->qr_code);
    }

    /**
     * And it says plainly that it was not filed, rather than queueing nothing
     * or throwing.
     */
    public function test_an_unonboarded_org_is_told(): void
    {
        $invoice = $this->draft('ACME-2');

        $this->issue($invoice)->assertSessionHas(
            'warning',
            fn (string $m): bool => str_contains($m, 'not been filed')
        );

        $this->assertDatabaseCount('invoice_submissions', 0);
    }

    /**
     * The chain fork, from the button that can cause it.
     */
    public function test_issuing_twice_is_refused(): void
    {
        $invoice = $this->draft('ACME-3');

        $this->issue($invoice);
        $hash = $invoice->fresh()->hash;

        // Without this the comparison below is vacuous: two nulls are equal,
        // so a first issuance that silently failed would read as a refused
        // second one.
        $this->assertNotNull($hash);

        // The wording, not merely the key: any failure flashes an error, and
        // this test is about the refusal Submitter::generate() raises rather
        // than about a signing error that happens to look the same.
        $this->issue($invoice)->assertSessionHas(
            'error',
            fn (string $m): bool => str_contains($m, 'cannot be issued again')
        );

        $this->assertSame($hash, $invoice->fresh()->hash, 'the hash was rewritten by a second issue');
    }

    public function test_the_qr_has_five_tags(): void
    {
        $invoice = $this->draft('ACME-4');

        $this->issue($invoice);

        $payload = base64_decode((string) $invoice->fresh()->qr_code);
        $tags = [];
        $offset = 0;

        while ($offset < strlen($payload) - 1) {
            $length = ord($payload[$offset + 1]);
            $tags[ord($payload[$offset])] = substr($payload, $offset + 2, $length);
            $offset += 2 + $length;
        }

        $this->assertSame([1, 2, 3, 4, 5], array_keys($tags));
    }

    private function draft(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Widget',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    private function issue(Invoice $invoice): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($this->member)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/invoices/'.$invoice->id.'/issue', ['_token' => self::TOKEN]));
    }
}
