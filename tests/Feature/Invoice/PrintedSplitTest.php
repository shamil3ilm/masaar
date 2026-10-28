<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\DTOs\PrintedField;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Templates\InvoiceTemplates;
use App\Domains\Invoice\Templates\TemplateLayout;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * A sale an insurer pays for, on paper as it is in its signed XML.
 *
 * ZATCA's General VAT Guideline Healthcare (January 2023), Example 10
 * (pp22-23): the invoice to the patient states the amounts "For account of
 * insurer" and "For account of patient". The signed document says so in its
 * note (BT-22, SplitNote) and the portal's page in its split panel; every
 * printed copy - the browser's print view and the PDF, in every template
 * layout and on every paper, the till's receipt among them - says so too,
 * with the claim, policy and member numbers. An invoice to the insurer
 * prints those numbers under their own names, not as a purchase order.
 */
class PrintedSplitTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->organization->id, ['role' => 'member', 'status' => 'active']);
    }

    /**
     * @return array<string, array{TemplateLayout, PaperSize}>
     */
    public static function layoutsAndPapers(): array
    {
        $cases = [];

        foreach (TemplateLayout::cases() as $layout) {
            foreach (PaperSize::cases() as $paper) {
                $cases[$layout->value.' on '.$paper->value] = [$layout, $paper];
            }
        }

        return $cases;
    }

    #[DataProvider('layoutsAndPapers')]
    public function test_split_prints_in_every_layout(TemplateLayout $layout, PaperSize $paper): void
    {
        app(InvoiceTemplates::class)->save(Level::Organization, Scope::organization((string) $this->organization->id), ['layout' => $layout->value], $this->organization, null);
        $patient = $this->patientInvoice();
        $insurer = $this->insurerInvoice();
        // Declared, so an invoice template cannot hide them and its editor shows them locked.
        $declared = array_map(fn ($field): string => $field->key, $this->printedFields());

        $this->assertContains('split_accounts', $declared);
        $this->assertContains('split_references', $declared);

        $expected = [
            'patient' => [$patient, [
                'For the account of the insurer', 'على حساب شركة التأمين', '69.00 SAR',
                'For the account of the patient', 'على حساب المريض', '277.15 SAR',
                'Gulf Health Insurance', 'CLM-2026-0042', 'POL-77', 'MEM-1234',
            ]],
            'insurer' => [$insurer, [
                'Claim or approval number', 'CLM-2026-0043', 'Policy number', 'POL-78', 'Member number', 'MEM-5678', 'Fahad Al Harbi',
            ]],
        ];

        foreach ($expected as $role => [$invoice, $values]) {
            $pages = [
                'print view' => (string) $this->actingAs($this->member)->withSession(['portal_org_id' => $this->organization->id])
                    ->get(route('portal.invoices.print', ['invoiceId' => $invoice->id, 'paper' => $paper->value]))->assertOk()->getContent(),
                'PDF' => app(InvoicePdf::class)->html($invoice, $this->organization, $paper),
            ];

            foreach ($pages as $where => $html) {
                foreach ($values as $value) {
                    $this->assertStringContainsString(e($value), $html, "the {$role}'s invoice's {$where} in {$layout->value} on {$paper->value} does not print {$value}");
                }

                if ($role === 'insurer') {
                    $this->assertStringNotContainsString('Purchase order', $html, 'the claim is not a purchase order');
                }
            }
        }
    }

    /** An invoice no insurer pays for prints none of it. */
    public function test_ordinary_invoice_prints_no_split(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 1);
        $html = app(InvoicePdf::class)->html($invoice, $this->organization, PaperSize::Roll80);

        $this->assertStringNotContainsString('For the account of', $html);
    }

    /** The patient's simplified invoice: 20% of 346.15 SAR the patient's. */
    private function patientInvoice(): Invoice
    {
        $split = $this->split(InsuranceSplit::PATIENT, [
            'patient_name' => 'Sara Al Qahtani', 'patient_share' => '277.15', 'insurer_share' => '69.00',
            'claim_reference' => 'CLM-2026-0042', 'policy_number' => 'POL-77', 'member_id' => 'MEM-1234',
        ]);

        return $this->sampleInvoice($this->organization, 'simplified', 2, state: ['insurance_split_id' => $split->id, 'split_role' => InsuranceSplit::PATIENT]);
    }

    /** The insurer's tax invoice, its references in BT-13, BT-12 and BT-10 as InsuranceSplits writes them. */
    private function insurerInvoice(): Invoice
    {
        $split = $this->split(InsuranceSplit::INSURER, [
            'patient_name' => 'Fahad Al Harbi', 'patient_share' => '0.00', 'insurer_share' => '0.00',
            'claim_reference' => 'CLM-2026-0043', 'policy_number' => 'POL-78', 'member_id' => 'MEM-5678',
        ]);

        // Linked as it is issued: an issued invoice is not rewritten.
        $invoice = $this->sampleInvoice($this->organization, 'standard', 1, state: [
            'insurance_split_id' => $split->id, 'split_role' => InsuranceSplit::INSURER,
            'order_reference' => 'CLM-2026-0043', 'contract_reference' => 'POL-78', 'buyer_reference' => 'MEM-5678',
        ]);
        InsuranceSplit::acrossTenants()->whereKey($split->id)->update(['insurer_share' => (string) $invoice->total]);

        return $invoice;
    }

    /** @param  array<string, string>  $attributes */
    private function split(string $recipient, array $attributes): InsuranceSplit
    {
        return InsuranceSplit::acrossTenants()->create([
            'org_id' => $this->organization->id, 'recipient' => $recipient, 'insurer_name' => 'Gulf Health Insurance',
            'method' => $recipient === InsuranceSplit::INSURER ? InsuranceSplit::NO_SHARE : 'percent', 'currency' => 'SAR',
            'sale_subtotal' => '0', 'sale_tax' => '0', 'sale_total' => '0', 'status' => InsuranceSplit::BILLED,
            ...$attributes,
        ]);
    }

    /** @return list<PrintedField> */
    private function printedFields(): array
    {
        return app(JurisdictionResolver::class)->forRules($this->organization)->presentation()->printedFields();
    }
}
