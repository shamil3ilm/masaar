<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Support\ShareAllocation;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The patient's share of an invoice an insurer pays part of, stated "for the
 * account of" the patient beside the insurer's (ZATCA General VAT Guideline
 * Healthcare, January 2023, §5.2 p24, Example 10 p23), and a part of an
 * invoice spread over its lines - to the halala, the parts always adding up.
 */
class ShareAllocationTest extends TestCase
{
    public function test_a_percentage_of_the_total(): void
    {
        $this->assertSame('69.00', ShareAllocation::patientShare('percent', '20', '345.00'));
        $this->assertSame('66.67', ShareAllocation::patientShare('percent', '33.335', '200.00'));
    }

    public function test_an_amount_is_the_amount(): void
    {
        $this->assertSame('95.00', ShareAllocation::patientShare('amount', '95', '345.00'));
    }

    public function test_lines_go_to_their_payer(): void
    {
        $this->assertSame('46.00', ShareAllocation::patientShare('lines', null, '276.00', ['46.00', '230.00'], ['patient', 'insurer']));
    }

    public function test_the_insurer_must_pay_some(): void
    {
        $this->assertRefused(fn () => ShareAllocation::patientShare('amount', '345.00', '345.00'), 'split.amount');
        $this->assertRefused(fn () => ShareAllocation::patientShare('lines', null, '100.00', ['100.00'], ['patient']), 'split');
        $this->assertRefused(fn () => ShareAllocation::patientShare('lines', null, '100.00', ['100.00'], [null]), 'lines.0.payer');
    }

    public function test_a_part_is_spread_exactly(): void
    {
        $this->assertSame(['33.34', '33.33', '33.33'], ShareAllocation::apportion('100.00', ['10.00', '10.00', '10.00']));
        $this->assertSame(['40.00', '60.00'], ShareAllocation::apportion('100.00', ['160.00', '240.00']));
    }

    private function assertRefused(callable $divide, string $field): void
    {
        try {
            $divide();
            $this->fail("It was not refused on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }
}
