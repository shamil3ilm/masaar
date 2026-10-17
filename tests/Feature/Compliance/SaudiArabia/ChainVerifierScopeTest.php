<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainEntry;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * A draft is not part of the hash chain, so it cannot break it.
 *
 * `fatoora:verify-hash-chain` selected every invoice for an organization with
 * no filter on icv or status, so a draft - which has no ICV and no hash, by
 * design, until it is issued - was read as a chain member with a missing hash
 * and an out-of-sequence counter. Any organization holding a single draft made
 * the check report FAILED.
 *
 * It runs on a schedule, and since the alerts channel was added its failures
 * reach a human. A daily job that always fails is worse than no job: it trains
 * people to ignore the one check that would tell them the chain has actually
 * forked, which is the defect this command exists to catch and the one that
 * cannot be repaired after the fact.
 */
class ChainVerifierScopeTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    public function test_a_draft_is_not_checked(): void
    {
        $org = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->issued($org, 'ISSUED-1', 1, FatooraConfig::GENESIS_PIH);

        // A draft, which is every invoice before someone presses issue. It
        // has no counter and no hash until then, by design.
        $this->makeInvoice($org, 'DRAFT-1', ['status' => 'draft']);

        // Asserted on what the draft provokes rather than on the command's
        // exit code: the chain may legitimately hold other faults, and this
        // is about whether a draft is read as a chain member at all.
        $this->artisan('fatoora:verify-hash-chain')
            ->doesntExpectOutputToContain('Missing hash')
            ->doesntExpectOutputToContain('DRAFT-1');
    }

    /**
     * And the check still earns its place: a genuine gap in the counter is
     * still reported. Excluding drafts must not become excluding everything.
     */
    public function test_a_real_gap_is_still_caught(): void
    {
        $org = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $first = $this->issued($org, 'ISSUED-1', 1, FatooraConfig::GENESIS_PIH);

        // 2 is missing. A counter that skips is the symptom of a document
        // that was issued and then lost.
        $this->issued($org, 'ISSUED-3', 3, (string) $first->hash);

        $this->artisan('fatoora:verify-hash-chain')->assertFailed();
    }

    /**
     * An issued invoice with no counter is reported, not skipped.
     *
     * This is what filtering on icv costs, paid back. Excluding drafts by
     * their missing counter also excludes anything else missing one - and an
     * invoice that reached Issued without a counter has been given to a
     * customer while being absent from the record that proves the sequence.
     *
     * Not reachable through normal issuance, which writes status, icv and
     * hash in one transaction. Reachable through an import, a backfill or a
     * manual repair, which are the circumstances where nobody is watching.
     */
    public function test_issued_without_a_counter_is_caught(): void
    {
        $org = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->issued($org, 'ISSUED-1', 1, FatooraConfig::GENESIS_PIH);
        $this->makeInvoice($org, 'ORPHAN-1', ['status' => 'issued']);

        $this->artisan('fatoora:verify-hash-chain')
            ->expectsOutputToContain('ORPHAN-1')
            ->assertFailed();
    }

    /**
     * The chain is read a page at a time now, and its position carries over
     * the page break: a sound chain longer than a page still passes.
     */
    public function test_a_chain_spanning_pages_passes(): void
    {
        $org = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $previous = FatooraConfig::GENESIS_PIH;

        foreach (range(1, 5) as $icv) {
            $previous = (string) $this->issued($org, "INV-{$icv}", $icv, $previous)->hash;
        }

        $this->artisan('fatoora:verify-hash-chain', ['--page' => 2])
            ->expectsOutputToContain('Verified 5 invoices')
            ->assertSuccessful();
    }

    /**
     * An issued invoice as issuance actually leaves it: a counter, a hash,
     * and the chain entry recording what it was built on.
     */
    private function issued(Organization $org, string $number, int $icv, string $previousHash): Invoice
    {
        $invoice = $this->makeInvoice($org, $number, [
            'status' => 'issued',
            'icv' => $icv,
            'hash' => base64_encode(hash('sha256', $number, true)),
        ]);

        ChainEntry::withoutTenantScope(fn () => ChainEntry::create([
            'org_id' => $org->id,
            'invoice_id' => $invoice->id,
            'invoice_hash' => $invoice->hash,
            'previous_hash' => $previousHash,
            'icv' => $icv,
            // ChainRecorder's own sentinel for a document signed without a
            // certificate, which is what Phase 1 issuance is.
            'certificate_id' => str_repeat('0', 64),
        ]));

        return $invoice;
    }
}
