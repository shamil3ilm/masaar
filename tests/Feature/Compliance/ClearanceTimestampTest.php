<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\SubmissionTracker;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When ZATCA clears a document, the moment it cleared has to be recorded.
 *
 * A 200 from ZATCA does not mean cleared: for a B2B document "REPORTED" means
 * received and not yet decided, and only "CLEARED" is terminal. So the
 * timestamp is written on a terminal clearance and withheld otherwise.
 *
 * SubmissionTracker wrote that timestamp to 'clearance_confirmed_at', which is
 * not a column and not fillable, so Eloquent discarded it on every response and
 * said nothing. cleared_at — the real column — stayed null, and the
 * InvoiceCleared event, which reads it, reported null for every cleared
 * invoice.
 *
 * Reflection is used because handleZatcaResponse() is private and the public
 * route to it needs a live ZATCA client. The mapping from response to stored
 * row is the thing under test, and this reaches it without mocking the API.
 */
class ClearanceTimestampTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_terminal_clearance_records_when_it_cleared(): void
    {
        $submission = $this->handle($this->response('CLEARED'));

        $this->assertNotNull(
            $submission->cleared_at,
            'A cleared document has no record of when it cleared.'
        );
        $this->assertSame('cleared', $submission->clearance_state);
    }

    /**
     * ZATCA has received the document and not yet decided, so there is no
     * moment of clearance to record.
     */
    public function test_pending_clearance_records_no_time(): void
    {
        $submission = $this->handle($this->response('PENDING'));

        $this->assertNull($submission->cleared_at);
        $this->assertSame('pending_clearance', $submission->clearance_state);
    }

    private function response(string $clearanceStatus): FatooraResponse
    {
        return FatooraResponse::fromApiResponse([
            'clearanceStatus' => $clearanceStatus,
            'validationResults' => ['status' => 'PASS'],
        ]);
    }

    /**
     * Run one ZATCA response through the tracker and return the stored row.
     */
    private function handle(FatooraResponse $response): InvoiceSubmission
    {
        $invoice = Invoice::create([
            'org_id' => $this->org->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Test Buyer',
        ]);

        $submission = InvoiceSubmission::create([
            'org_id' => $this->org->id,
            'invoice_id' => $invoice->id,
            'state' => 'submitted',
            'submission_type' => 'clearance',
        ]);

        $method = new \ReflectionMethod(SubmissionTracker::class, 'handleZatcaResponse');
        $method->invoke($this->app->make(SubmissionTracker::class), $submission, $response);

        return $submission->fresh();
    }
}
