<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Compliance;

use App\Domains\Compliance\Fatoora\Services\ClearanceState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What ZATCA's response means for a document's clearance.
 *
 * The distinction that matters: a standard (B2B) document is cleared only on
 * "CLEARED", while a simplified (B2C) one is finished on "REPORTED". The same
 * word is terminal for one and provisional for the other, so the invoice type
 * decides which field is even read.
 */
class ClearanceStateTest extends TestCase
{
    private ClearanceState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = new ClearanceState;
    }

    /**
     * @return array<string, array{array<string, mixed>, bool, string, bool}>
     */
    public static function responses(): array
    {
        return [
            // Standard (B2B): clearance decides.
            'b2b cleared' => [['clearanceStatus' => 'CLEARED'], false, 'cleared', true],
            'b2b refused' => [['clearanceStatus' => 'NOT_CLEARED'], false, 'rejected', true],
            'b2b pending' => [['clearanceStatus' => 'PENDING'], false, 'pending_clearance', false],

            // A B2B document that ZATCA has only acknowledged is not cleared;
            // reading REPORTED as success here is the failure this guards.
            'b2b reported is not cleared' => [
                ['clearanceStatus' => null, 'reportingStatus' => 'REPORTED'], false, 'conditionally_accepted', false,
            ],

            // Simplified (B2C): reporting decides.
            'b2c reported' => [['reportingStatus' => 'REPORTED'], true, 'reported', true],
            'b2c refused' => [['reportingStatus' => 'NOT_REPORTED'], true, 'rejected', true],
            'b2c pending' => [['reportingStatus' => 'PENDING'], true, 'pending_clearance', false],

            // A status neither side recognises stays open rather than being
            // called either way.
            'unrecognised status' => [['clearanceStatus' => 'SOMETHING_NEW'], false, 'conditionally_accepted', false],
            'no status at all' => [[], false, 'unknown', false],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    #[DataProvider('responses')]
    public function test_state_follows_the_response(
        array $response,
        bool $isSimplified,
        string $expected,
        bool $isTerminal
    ): void {
        $result = $this->state->parseResponse($response, $isSimplified);

        $this->assertSame($expected, $result['state']);
        $this->assertSame($isTerminal, $result['is_terminal']);
    }

    /**
     * Errors outrank the status field. ZATCA can return CLEARED alongside
     * error messages, and the errors are the answer.
     */
    public function test_errors_reject_whatever_the_status_says(): void
    {
        $result = $this->state->parseResponse([
            'clearanceStatus' => 'CLEARED',
            'validationResults' => [
                'errorMessages' => [['code' => 'BR-KSA-01', 'message' => 'Missing VAT number']],
            ],
        ]);

        $this->assertSame('rejected', $result['state']);
        $this->assertTrue($result['is_terminal']);
    }

    /**
     * Warnings do not reject — a warned document still clears.
     */
    public function test_warnings_do_not_reject(): void
    {
        $result = $this->state->parseResponse([
            'clearanceStatus' => 'CLEARED',
            'validationResults' => [
                'warningMessages' => [['code' => 'BR-KSA-09', 'message' => 'Rounding differs']],
            ],
        ]);

        $this->assertSame('cleared', $result['state']);
        $this->assertSame([[
            'code' => 'BR-KSA-09',
            'message' => 'Rounding differs',
            'category' => 'general',
        ]], $result['warnings']);
    }

    /**
     * A message missing every field still produces a well-formed entry, so a
     * caller reading ['code'] does not fail on a sparse ZATCA response.
     */
    public function test_sparse_messages_are_filled_in(): void
    {
        $result = $this->state->parseResponse([
            'clearanceStatus' => 'CLEARED',
            'validationResults' => ['warningMessages' => [[]]],
        ]);

        $this->assertSame([[
            'code' => 'UNKNOWN',
            'message' => '',
            'category' => 'general',
        ]], $result['warnings']);
    }
}
