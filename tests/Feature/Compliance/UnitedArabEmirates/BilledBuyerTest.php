<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\InvoiceRules;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How a UAE seller names an organization as its buyer on an invoice drafted
 * straight from that organization's details: by its TRN, or not at all.
 */
class BilledBuyerTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_buyer_is_named_by_trn(): void
    {
        $buyer = $this->uaeOrganization();
        $buyer->forceFill(['vat_number' => '100765432100003'])->save();

        $this->assertSame(
            ['buyer_vat_number' => '100765432100003', 'buyer_id' => null, 'buyer_id_scheme' => null],
            app(InvoiceRules::class)->billedBuyer($buyer),
        );
    }

    public function test_buyer_without_trn_is_refused(): void
    {
        $buyer = $this->uaeOrganization();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Gulf Trading LLC has no TRN, so no invoice was issued.');

        app(InvoiceRules::class)->billedBuyer($buyer);
    }
}
