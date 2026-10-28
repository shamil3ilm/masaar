<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * An invoice reaches ZATCA once, from this route as well.
 *
 * Everything that guards a submission lives in SubmissionTracker::submit():
 * the idempotency key, the duplicate check, the submission guard, the quota
 * reservation, and the invoice_submissions row that records the attempt.
 *
 * POST /api/compliance/sa/submit called Submitter::submit() instead, which
 * checks that the invoice has a counter and a hash and that the branch is
 * onboarded, and then sends. So an ERP that retried after a timeout, or a
 * client that double-posted, filed the same document twice with the
 * authority - and because no invoice_submissions row was ever written, the
 * second filing was invisible on /portal/submissions and in the admin logs
 * too. The platform had no record it had happened.
 *
 * SubmissionRaceTest covers the same property for the tracker's own path.
 * This is about the second door into the same room.
 */
class ComplianceSubmitTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private string $token;

    private User $user;

    /**
     * What the authority answers.
     *
     * A property rather than a second Http::fake() in the test that needs a
     * failure: Laravel appends stubs rather than replacing them, so a later
     * fake for the same URL never matches - the one from setUp wins and the
     * test silently exercises the success path.
     */
    private int $authorityStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            // Submitter::submit refuses an organization that has not
            // onboarded, which is correct and has to be satisfied rather than
            // worked around.
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $credentials = $this->selfSignedCredentials();

        app(CredentialStore::class)->put(
            $this->acme->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret']
        );

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->user = $user;
        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);

        Http::fake([
            '*/invoices/*' => fn () => $this->authorityStatus === 200
                ? Http::response(['clearanceStatus' => 'CLEARED', 'validationResults' => ['status' => 'PASS']])
                : Http::response(['message' => 'upstream unavailable'], $this->authorityStatus),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    /**
     * Two posts for one invoice send one document.
     *
     * The second is the ERP retrying a request whose answer it never saw,
     * which is the ordinary case rather than the unusual one.
     */
    public function test_two_posts_send_one_document(): void
    {
        $invoice = $this->issued('ACME-1');

        $this->submit($invoice)->assertOk();
        $this->submit($invoice);

        $this->assertSame(1, $this->zatcaCalls(), 'the invoice was filed twice');
    }

    /**
     * Just after midnight in Riyadh, while UTC is still on the day before.
     *
     * The guard read issue_date - the Kingdom's business date, held as
     * midnight UTC - as the moment of issue. From 21:00 to 24:00 UTC that
     * midnight is up to three hours ahead, so every invoice issued in those
     * hours was refused as "in the future". CI found it by running at 00:20
     * Riyadh time.
     */
    public function test_files_just_after_riyadh_midnight(): void
    {
        $this->travelTo(now('UTC')->setTime(21, 30));
        // A token minted before the clock moved would be issued "later" than
        // now and refused; mint it on the travelled clock.
        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->user);

        $invoice = $this->issued('ACME-NIGHT');

        $this->submit($invoice)->assertOk();
    }

    /**
     * And the attempt is recorded where everything else records one.
     *
     * Without a row here the submission is absent from /portal/submissions,
     * from the admin logs and from the deadline report - so an invoice filed
     * through this route looked, to the whole platform, like one that had
     * never been filed at all.
     */
    public function test_a_submission_row_is_written(): void
    {
        $invoice = $this->issued('ACME-2');

        $this->submit($invoice)->assertOk();

        $this->assertSame(
            1,
            InvoiceSubmission::acrossTenants()->where('invoice_id', $invoice->id)->count(),
            'the submission left no record'
        );
    }

    /**
     * A draft is still refused before anything is sent.
     */
    public function test_a_draft_is_refused(): void
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'ACME-3',
            'type' => 'simplified',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $this->submit($invoice)->assertStatus(422);

        $this->assertSame(0, $this->zatcaCalls());
    }

    /**
     * A failure still answers with its own status, not a flat 422.
     *
     * The controller took some care over this: a kill switch, an unreachable
     * authority and a timeout are not the same as a malformed invoice, and
     * answering 422 to all three tells an ERP to stop retrying and go fix a
     * document that is fine. Moving the route onto the tracker had to carry
     * that across, because the tracker reports a failure as an array rather
     * than as the FatooraResponse the old code read the code off.
     */
    public function test_a_failure_keeps_its_status(): void
    {
        $invoice = $this->issued('ACME-4');

        $this->authorityStatus = 503;

        $response = $this->submit($invoice);

        $this->assertNotSame(422, $response->status(), 'every failure answered 422 again');

        $response->assertJsonPath('error.retryable', true)
            ->assertJsonStructure(['error' => ['code', 'category']]);
    }

    private function submit(Invoice $invoice): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/compliance/sa/submit/'.$invoice->id);
    }

    private function zatcaCalls(): int
    {
        return count(Http::recorded(fn (Request $request) => str_contains($request->url(), '/invoices/')));
    }

    private function issued(string $number): Invoice
    {
        $invoice = Invoice::acrossTenants()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in customer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Widget',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);

        return $invoice->fresh(['lines']);
    }
}
