<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Module;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Services\KillSwitch;
use Tests\TestCase;

/**
 * What Saudi e-invoicing has, as Access asks it: every document ZATCA
 * defines, simplified and standard, credit and debit notes, the PDF, and the
 * offline queue; nothing to receive, since ZATCA hands nothing to the buyer.
 */
class FeatureSupportTest extends TestCase
{
    public function test_everything_but_receiving(): void
    {
        $support = app(Module::class)->features();

        foreach (Feature::cases() as $feature) {
            $this->assertSame($feature !== Feature::ReceiveInvoices, $support->supportsFeature($feature), $feature->value);
        }
    }

    /** Issuing signs and filing submits, and each has its switch. */
    public function test_signing_and_submission_switches(): void
    {
        $support = app(Module::class)->features();

        $this->assertSame([KillSwitch::SWITCH_SIGNING], $support->killSwitchesFor(Feature::IssueStandard));
        $this->assertSame([KillSwitch::SWITCH_SUBMISSION], $support->killSwitchesFor(Feature::FileWithAuthority));
        $this->assertSame([], $support->killSwitchesFor(Feature::Webhooks));
    }
}
