<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Two documents must never claim the same predecessor.
 *
 * PreviousHashTest holds the accessor to the right answer for one caller at a
 * time. This holds the chain to the property that makes it a chain: each hash
 * is claimed as the predecessor exactly once.
 *
 * It used not to hold. ICV was allocated when the row was created and the hash
 * written later, at issuance, and the PIH accessor skips anything unhashed —
 * so a document issued while a lower-numbered draft was still unsigned chained
 * straight past it, and when that draft was issued in turn both named the same
 * predecessor. Neither unique index catches that: hash_chain_history
 * constrains (device_id, icv) and invoice_id, and two rows at different positions
 * may share a previous_hash. Concurrency was one way in and out-of-order
 * issuance was the other, needing no concurrency at all.
 *
 * The counter is allocated at issuance now, under the same organization-row
 * lock that reads the predecessor, so a draft holds no position in the chain
 * and there is nothing to chain past.
 */
class ChainForkTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $credentials = $this->selfSignedCredentials();

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret'],
        );
    }

    /**
     * A draft is not in the chain, so it holds no counter.
     */
    public function test_a_draft_carries_no_counter(): void
    {
        $draft = $this->draft('DRAFT-1');

        $this->assertNull($draft->icv);
    }

    /**
     * The counter is taken at issuance, and taken once.
     */
    public function test_issuance_allocates_the_counter(): void
    {
        $first = $this->issue('INV-1');
        $second = $this->issue('INV-2');

        $this->assertSame(1, $first->fresh()->icv);
        $this->assertSame(2, $second->fresh()->icv);
    }

    /**
     * The fork, reproduced the way it used to happen: a draft sits unissued
     * between two documents. It no longer occupies a position, so the two
     * that are issued are adjacent and each predecessor is claimed once.
     */
    public function test_draft_does_not_split_chain(): void
    {
        $first = $this->issue('INV-1');

        $this->draft('ABANDONED');

        $second = $this->issue('INV-2');

        $this->assertSame(2, $second->fresh()->icv, 'the abandoned draft took a position in the chain');
        $this->assertSame(
            $first->fresh()->hash,
            $second->fresh()->previous_invoice_hash,
            'the second document chained past the first',
        );
    }

    /**
     * The property itself: across every issued document, no hash is named as
     * the predecessor twice.
     */
    public function test_predecessor_claimed_once(): void
    {
        $this->issue('INV-1');
        $this->draft('ABANDONED-A');
        $this->issue('INV-2');
        $this->draft('ABANDONED-B');
        $this->issue('INV-3');

        $issued = Invoice::withoutTenantScope(
            fn () => Invoice::query()
                ->where('org_id', $this->organization->id)
                ->whereNotNull('hash')
                ->orderBy('icv')
                ->get()
        );

        $claimed = $issued
            ->map(fn (Invoice $invoice): ?string => $invoice->previous_invoice_hash)
            ->filter()
            ->values()
            ->all();

        $this->assertSame(
            $claimed,
            array_values(array_unique($claimed)),
            'a hash was claimed as the predecessor by more than one document',
        );

        $this->assertSame([1, 2, 3], $issued->pluck('icv')->all(), 'the counter is not contiguous');
    }

    /**
     * Re-issuing a document is refused, and the refusal is the point.
     *
     * This test used to assert that generate() could be called again and would
     * keep the counter. That was true, and it was not enough: the counter held
     * while hash, qr_code and signed_xml were rewritten, and ChainRecorder's
     * updateOrCreate rewrote the chain entry with them. The next invoice's
     * entry still named the old hash, so the chain forked silently and the
     * stored document stopped matching what ZATCA holds.
     *
     * Internal callers that legitimately re-enter - the submission job
     * retrying, and Submitter's own submit() - pass reissue: true, and they
     * only reach it when the hash is missing.
     */
    public function test_reissuing_a_document_is_refused(): void
    {
        $invoice = $this->issue('INV-1');
        $before = $invoice->fresh();

        try {
            app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization);
            $this->fail('Re-issuing was allowed.');
        } catch (FatooraException) {
            // Expected. The assertions below are the point: refusing is only
            // useful if nothing was written on the way out.
        }

        $after = $invoice->fresh();

        $this->assertSame($before->hash, $after->hash, 'the hash was rewritten');
        $this->assertSame($before->icv, $after->icv);
        $this->assertSame($before->qr_code, $after->qr_code);
    }

    /**
     * And the explicit opt-in still works, for the callers that need it.
     */
    public function test_reissue_is_allowed_when_asked(): void
    {
        $invoice = $this->issue('INV-1');
        $allocated = $invoice->fresh()->icv;

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization, reissue: true);

        $this->assertSame($allocated, $invoice->fresh()->icv);
    }

    private function draft(string $number): Invoice
    {
        $invoice = Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '300000000000003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        return $invoice;
    }

    private function issue(string $number): Invoice
    {
        $invoice = $this->draft($number);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->organization);

        return $invoice;
    }
}
