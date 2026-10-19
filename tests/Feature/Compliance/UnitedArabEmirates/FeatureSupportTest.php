<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Module;
use App\Domains\Platform\Access\Feature;
use Tests\TestCase;

/**
 * What UAE e-invoicing has, as Access asks it: PINT AE is business to
 * business, so no simplified invoice and no debit note; the e-invoice is the
 * document, so no PDF; the service provider carries everything, so nothing
 * waits offline. Receiving is there: other suppliers' documents arrive over
 * the network.
 */
class FeatureSupportTest extends TestCase
{
    public function test_business_documents_and_receiving(): void
    {
        $support = app(Module::class)->features();
        $absent = [Feature::IssueSimplified, Feature::DebitNotes, Feature::OfflineMode];

        foreach (Feature::cases() as $feature) {
            $this->assertSame(! in_array($feature, $absent, true), $support->supportsFeature($feature), $feature->value);
        }

        $this->assertTrue($support->supportsFeature(Feature::ReceiveInvoices));
        $this->assertTrue($support->supportsFeature(Feature::CreditNotes));
        // A regular tax invoice beside the e-invoice, for a buyer not yet on
        // electronic invoicing (Electronic Invoicing Guidelines v1.1, 10.2.2).
        $this->assertTrue($support->supportsFeature(Feature::PdfAndEmail));
    }

    /** Only the shared issuance switch: sending is the network's. */
    public function test_no_switches_of_its_own(): void
    {
        foreach (Feature::cases() as $feature) {
            $this->assertSame([], app(Module::class)->features()->killSwitchesFor($feature), $feature->value);
        }
    }
}
