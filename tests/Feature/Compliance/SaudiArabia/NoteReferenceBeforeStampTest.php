<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A credit or debit note's reference is checked before it is stamped.
 *
 * IR Art 54(4): a note carries "a reference to the sequential number of the
 * Tax Invoice issued in respect of the initial Supply"; BR-KSA-56 wants it
 * in the XML. Whether that invoice exists in the organization, is not
 * itself a note, has the note's type, has credit left and was accepted by
 * ZATCA (cleared or reported, with or without warnings) was checked only
 * when the note was filed - after its counter was spent and its stamp
 * applied, and for a simplified note after the customer had it.
 */
class NoteReferenceBeforeStampTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 'device-secret',
        ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusals(): array
    {
        return [
            'missing original' => ['NO-SUCH-INVOICE', 'not found'],
            'over the original' => ['ORIG-1', 'exceed'],
            'another organization' => ['RIVAL-1', 'not found'],
            'original not yet accepted' => ['ORIG-WAITING', 'ORIG-WAITING is issued'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_bad_reference_spends_no_counter(string $reference, string $why): void
    {
        $this->original('ORIG-1', '100.00');
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA', 'vat_number' => '311111111111113']);
        $this->original('RIVAL-1', '1000.00', $rival);
        $this->original('ORIG-WAITING', '1000.00', status: 'issued');

        $note = $this->note($reference, '500.00');

        try {
            app(Submitter::class)->generate($note, $this->acme);
            $this->fail('the note was stamped against a reference that cannot stand');
        } catch (FatooraException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }

        $note->refresh();
        $this->assertSame(InvoiceStatus::Draft, $note->status);
        $this->assertNull($note->icv, 'a counter was spent on a refused note');
        $this->assertNull($note->hash);
    }

    public function test_a_good_reference_is_stamped(): void
    {
        $this->original('ORIG-2', '1000.00');

        $note = $this->note('ORIG-2', '100.00');
        app(Submitter::class)->generate($note, $this->acme);

        $this->assertNotNull($note->fresh()->icv);
    }

    private function original(string $number, string $total, ?Organization $organization = null, string $status = 'accepted'): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => ($organization ?? $this->acme)->id, 'invoice_number' => $number, 'type' => 'simplified', 'document_type' => 'invoice',
            'status' => $status, 'issue_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => $total, 'tax_amount' => '0.00', 'total' => $total,
        ]));
    }

    private function note(string $reference, string $net): Invoice
    {
        $vat = number_format((float) $net * 0.15, 2, '.', '');
        $note = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id, 'invoice_number' => 'CN-'.$reference, 'type' => 'simplified', 'document_type' => 'credit_note',
            'billing_ref' => $reference, 'adjustment_reason' => 'Goods returned',
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => $net, 'tax_amount' => $vat, 'total' => number_format((float) $net + (float) $vat, 2, '.', ''),
        ]));
        $note->lines()->create([
            'description' => 'Returned widget', 'quantity' => '1.000', 'unit_price' => $net,
            'tax_rate' => '15.00', 'tax_amount' => $vat, 'line_total' => $net,
        ]);

        return $note->fresh(['lines']);
    }
}
