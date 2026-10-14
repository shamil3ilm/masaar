<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Models\ChainEntry;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two requests issuing one draft issue it once.
 *
 * generate() decided whether the invoice was still a draft from the copy the
 * caller had loaded, before it took the organization lock. Two requests for
 * the same draft - a double-clicked Issue button - both loaded it as a draft,
 * both passed, and took the lock in turn. The second still held icv = null in
 * its copy, so it allocated a second counter and re-signed the document over
 * the first: the first counter left as a gap in the chain, and the hash of an
 * invoice already issued rewritten.
 *
 * The decision is made under the lock now, on the row as it stands. Two
 * loaded copies are what two requests hold, so this needs no concurrency.
 */
class IssuanceRaceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003']);
    }

    public function test_a_stale_copy_cannot_issue_again(): void
    {
        $draft = $this->draft();
        $first = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->find($draft->id));
        $second = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->find($draft->id));

        app(Submitter::class)->generate($first, $this->acme);
        $issued = $draft->fresh();

        try {
            app(Submitter::class)->generate($second, $this->acme);
            $this->fail('The second request issued the invoice again.');
        } catch (FatooraException) {
            // Refused: it is not a draft any more.
        }

        $this->assertSame($issued->icv, $draft->fresh()->icv, 'a second counter was allocated');
        $this->assertSame($issued->hash, $draft->fresh()->hash, 'the issued document was re-signed');
        $this->assertSame(1, ChainEntry::withoutTenantScope(fn () => ChainEntry::count()));
    }

    /**
     * The internal callers that finish a half-done issuance pass reissue, and
     * decide that from their own copy too. If another request has finished it
     * meanwhile, there is nothing left to finish.
     */
    public function test_finishing_a_finished_issue_changes_nothing(): void
    {
        $draft = $this->draft();
        $stale = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->find($draft->id));

        app(Submitter::class)->generate($draft->fresh(['lines']), $this->acme);
        $issued = $draft->fresh();

        app(Submitter::class)->generate($stale, $this->acme, reissue: true);

        $this->assertSame($issued->icv, $draft->fresh()->icv);
        $this->assertSame($issued->hash, $draft->fresh()->hash);
    }

    private function draft(): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-1',
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Widget',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        return $invoice;
    }
}
