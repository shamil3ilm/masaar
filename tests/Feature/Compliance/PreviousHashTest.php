<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every document carries the previous one's hash, and ZATCA checks the chain.
 *
 * There is no previous_invoice_hash column, and Eloquent answers null for an
 * attribute a model does not have rather than failing. Three of the five paths
 * that build a document read it anyway — two by name, and Submitter::submit()
 * by omitting the argument altogether — and XmlBuilder turns a null into the
 * genesis PIH. So the documents that actually reached the authority each
 * claimed to be the first in their chain, and nothing anywhere failed.
 *
 * The attribute is real now, and these hold it to the two things the chain
 * depends on: the right predecessor, and the right tenant's predecessor.
 */
class PreviousHashTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
    }

    public function test_first_invoice_has_no_predecessor(): void
    {
        $first = $this->invoice($this->acme, icv: 1, hash: 'HASH-1');

        $this->assertNull($first->previous_invoice_hash);
    }

    public function test_second_invoice_chains_to_the_first(): void
    {
        $this->invoice($this->acme, icv: 1, hash: 'HASH-1');
        $second = $this->invoice($this->acme, icv: 2, hash: 'HASH-2');

        $this->assertSame('HASH-1', $second->previous_invoice_hash);
    }

    /**
     * Each tenant runs its own chain. Reading across would both leak one
     * tenant's hash into another's document and break both chains at once.
     */
    public function test_chain_does_not_cross_tenants(): void
    {
        $this->invoice($this->acme, icv: 1, hash: 'ACME-1');
        $rivalSecond = $this->invoice($this->rival, icv: 2, hash: 'RIVAL-2');

        $this->assertNull($rivalSecond->previous_invoice_hash);
    }

    /**
     * Ordered by ICV, not by insertion. A document created later but numbered
     * earlier is not this invoice's predecessor.
     */
    public function test_predecessor_is_chosen_by_icv(): void
    {
        $this->invoice($this->acme, icv: 3, hash: 'HASH-3');
        $this->invoice($this->acme, icv: 1, hash: 'HASH-1');
        $this->invoice($this->acme, icv: 2, hash: 'HASH-2');

        $fourth = $this->invoice($this->acme, icv: 4, hash: 'HASH-4');

        $this->assertSame('HASH-3', $fourth->previous_invoice_hash);
    }

    /**
     * A draft has no hash and is not part of the chain, so it must not be
     * returned as a predecessor — that would put a null into the next document.
     */
    public function test_unhashed_drafts_are_skipped(): void
    {
        $this->invoice($this->acme, icv: 1, hash: 'HASH-1');
        $this->invoice($this->acme, icv: 2, hash: null);

        $third = $this->invoice($this->acme, icv: 3, hash: 'HASH-3');

        $this->assertSame('HASH-1', $third->previous_invoice_hash);
    }

    /**
     * The queue and offline paths run without a request, where the tenant scope
     * would otherwise scope to null, find nothing, and hand back the genesis
     * PIH — indistinguishable from a genuinely first invoice.
     */
    public function test_resolves_without_a_tenant_context(): void
    {
        $this->invoice($this->acme, icv: 1, hash: 'HASH-1');
        $second = $this->invoice($this->acme, icv: 2, hash: 'HASH-2');

        $this->assertSame('HASH-1', $second->previous_invoice_hash);
    }

    private function invoice(Organization $org, int $icv, ?string $hash): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $org->id,
            'invoice_number' => $org->name.'-'.$icv,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            'icv' => $icv,
            'hash' => $hash,
        ]));
    }
}
