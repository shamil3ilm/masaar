<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceNumbering;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Invoices the platform numbers, in the organization's own format.
 *
 * A draft may leave its number out and takes the next one when it is issued,
 * under the lock that allocates the ZATCA counter - so issued invoices run
 * without gaps, and the number is in the document that is signed.
 */
class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now('Asia/Riyadh')->setDate(2026, 10, 15)->setTime(10, 0));

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        // A certificate, so issuing signs and the number can be read back
        // out of the document itself.
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);
        $credentials = $this->selfSignedCredentials();
        app(CredentialStore::class)->put((string) $this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $credentials['privateKey'],
            'pcsid' => $credentials['certificate'],
            'secret' => 'device-secret',
        ]);
    }

    public function test_a_draft_is_numbered_at_issue(): void
    {
        $first = $this->issue($this->draft());
        $second = $this->issue($this->draft());

        $this->assertSame('INV-2026-00001', $first->invoice_number);
        $this->assertSame('INV-2026-00002', $second->invoice_number);
        // In the document itself, not only the row: BT-1 is signed.
        $this->assertStringContainsString('<cbc:ID>INV-2026-00001</cbc:ID>', (string) $first->signed_xml);
    }

    /**
     * A number typed by hand is the invoice's, and the sequence steps over it
     * rather than giving it out twice.
     */
    public function test_a_typed_number_is_stepped_over(): void
    {
        $this->issue($this->draft('INV-2026-00001'));

        $this->assertSame('INV-2026-00002', $this->issue($this->draft())->invoice_number);
    }

    /**
     * Numbers already taken - after the reset period changed, say - are
     * jumped past in one step, not stepped over one at a time under the lock.
     */
    public function test_taken_numbers_are_jumped_past(): void
    {
        foreach (range(1, 40) as $n) {
            $this->draft(sprintf('INV-2026-%05d', $n));
        }

        $this->assertSame('INV-2026-00041', $this->allocate('invoice'));
        $this->assertSame('INV-2026-00042', $this->allocate('invoice'));
    }

    /**
     * A branch without a sequence of its own is numbered from the
     * organization's, and its preview says so.
     */
    public function test_a_branch_inherits_the_organization_series(): void
    {
        $branch = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id,
            'name' => 'Jeddah',
            'device_serial' => 'EGS-JED',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]));

        $this->withToken($this->token('admin'))
            ->putJson('/api/settings/numbering/invoice', ['pattern' => 'ACME-{YYYY}-{SEQ}', 'padding' => 5, 'reset' => 'yearly'])
            ->assertOk();

        $this->withToken($this->token('member'))
            ->getJson('/api/settings/numbering?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('data.sequences.0.inherited', true)
            ->assertJsonPath('data.sequences.0.next', 'ACME-2026-00001');
    }

    public function test_each_document_type_has_its_series(): void
    {
        $this->assertSame('CN-2026-00001', $this->allocate('credit_note'));
        $this->assertSame('DN-2026-00001', $this->allocate('debit_note'));
        $this->assertSame('INV-2026-00001', $this->allocate('invoice'));
    }

    public function test_the_counter_restarts_with_the_year(): void
    {
        $this->travelTo(now('Asia/Riyadh')->setDate(2026, 12, 31)->setTime(23, 0));
        $this->assertSame('INV-2026-00001', $this->allocate('invoice'));

        $this->travelTo(now('Asia/Riyadh')->setDate(2027, 1, 1)->setTime(1, 0));
        $this->assertSame('INV-2027-00001', $this->allocate('invoice'));
    }

    /**
     * A UAE organization's periods are read on Dubai's clock. Half past
     * midnight on New Year's Day in Dubai is still 31 December in Riyadh, so
     * on the Saudi clock that invoice would have stayed in the old year.
     */
    public function test_uae_periods_follow_dubai_time(): void
    {
        $uae = Organization::create(['name' => 'Gulf Trading', 'country' => 'AE']);

        $this->travelTo(now('Asia/Dubai')->setDate(2026, 12, 31)->setTime(23, 30));
        $this->assertSame('INV-2026-00001', $this->allocate('invoice', $uae));

        $this->travelTo(now('Asia/Dubai')->setDate(2027, 1, 1)->setTime(0, 30));
        $this->assertSame('INV-2027-00001', $this->allocate('invoice', $uae));
    }

    /**
     * The same instant - 00:30 in Dubai, 23:30 in Riyadh on 31 December -
     * leaves a Saudi invoice in the old year, as it always has.
     */
    public function test_saudi_periods_follow_riyadh_time(): void
    {
        $this->travelTo(now('Asia/Dubai')->setDate(2027, 1, 1)->setTime(0, 30));

        $this->assertSame('INV-2026-00001', $this->allocate('invoice'));
    }

    /**
     * Allocated inside the issuing transaction: an issue that fails spends
     * no number, so the next one does not leave a gap.
     */
    public function test_a_failed_issue_spends_no_number(): void
    {
        $failing = true;
        Invoice::updating(function (Invoice $invoice) use (&$failing): void {
            if ($failing && $invoice->isDirty('status')) {
                throw new RuntimeException('invoice store down');
            }
        });

        $draft = $this->draft();

        try {
            app(Submitter::class)->generate($draft, $this->acme);
        } catch (RuntimeException) {
        }

        $this->assertNull($draft->fresh()->invoice_number);
        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);

        $failing = false;

        $this->assertSame('INV-2026-00001', $this->issue($draft->fresh(['lines']))->invoice_number);
    }

    public function test_settings_shape_the_number(): void
    {
        $this->withToken($this->token('admin'))
            ->putJson('/api/settings/numbering/invoice', [
                'pattern' => 'TX/{YY}{MM}/{SEQ}',
                'padding' => 4,
                'reset' => 'monthly',
                'next_number' => 1001,
            ])
            ->assertOk()
            ->assertJsonPath('data.sequences.0.next', 'TX/2610/1001');

        $this->assertSame('TX/2610/1001', $this->issue($this->draft())->invoice_number);
    }

    /**
     * A counter that restarts repeats its numbers unless the period is in
     * them, and ZATCA refuses a number that does not identify one invoice.
     */
    public function test_a_restarting_counter_shows_its_period(): void
    {
        $this->withToken($this->token('admin'))
            ->putJson('/api/settings/numbering/invoice', ['pattern' => 'INV-{SEQ}', 'padding' => 5, 'reset' => 'yearly'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pattern'], 'error.details');

        $this->withToken($this->token('admin'))
            ->putJson('/api/settings/numbering/invoice', ['pattern' => 'INV-{SEQ}', 'padding' => 5, 'reset' => 'never'])
            ->assertOk();
    }

    public function test_two_series_cannot_share_a_pattern(): void
    {
        $this->withToken($this->token('admin'))
            ->putJson('/api/settings/numbering/credit_note', ['pattern' => 'INV-{YYYY}-{SEQ}', 'padding' => 5, 'reset' => 'yearly'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pattern'], 'error.details');
    }

    public function test_only_an_admin_changes_numbering(): void
    {
        $this->withToken($this->token('member'))
            ->getJson('/api/settings/numbering')
            ->assertOk()
            ->assertJsonPath('data.sequences.0.next', 'INV-2026-00001');

        $this->withToken($this->token('member'))
            ->putJson('/api/settings/numbering/invoice', ['pattern' => 'X-{YYYY}-{SEQ}', 'padding' => 5, 'reset' => 'yearly'])
            ->assertForbidden();
    }

    public function test_the_portal_page_saves_numbering(): void
    {
        $admin = User::factory()->create();
        $admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)->withSession(['portal_org_id' => $this->acme->id])
            ->get('/portal/numbering')
            ->assertOk()
            ->assertSee('INV-2026-00001');

        $this->actingAs($admin)->withSession(['portal_org_id' => $this->acme->id])
            ->post('/portal/numbering/invoice', ['pattern' => 'ACME-{YYYY}-{SEQ}', 'padding' => 6, 'reset' => 'yearly'])
            ->assertRedirect(route('portal.numbering.edit'));

        $this->assertSame('ACME-2026-000001', $this->allocate('invoice'));
    }

    private function allocate(string $documentType, ?Organization $organization = null): string
    {
        $invoice = new Invoice(['org_id' => ($organization ?? $this->acme)->id, 'document_type' => $documentType]);

        return app(InvoiceNumbering::class)->allocate($invoice);
    }

    private function issue(Invoice $draft): Invoice
    {
        app(Submitter::class)->generate($draft, $this->acme);

        return $draft->fresh();
    }

    private function draft(?string $number = null): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'tax_category' => 'S',
            'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    private function token(string $role): string
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return JWTAuth::claims(['org_id' => $this->acme->id, 'role' => $role])->fromUser($user);
    }
}
