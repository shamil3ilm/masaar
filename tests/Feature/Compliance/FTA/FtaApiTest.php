<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\FTA;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\FTA\Models\FtaSubmission;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Reading UAE FTA submissions over the tenant API.
 *
 * FtaSubmissionTest covers what the service does with the authority's
 * answers; this covers what a caller may see of the result.
 */
class FtaApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'AE']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'AE']);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_index_lists_own_submissions(): void
    {
        $this->submission($this->acme, 'accepted');
        $this->submission($this->acme, 'failed');
        $this->submission($this->rival, 'accepted');

        $response = $this->withToken($this->token)->getJson('/api/compliance/ae/submissions')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.per_page', 25)
            ->assertJsonCount(2, 'data.data');

        $this->assertSame(['id', 'invoice_number'], array_keys($response->json('data.data.0.invoice')));
        $this->assertSame([$this->acme->id, $this->acme->id], array_column($response->json('data.data'), 'org_id'));
    }

    public function test_status_reports_own_submission(): void
    {
        $submission = $this->submission($this->acme, 'accepted', [
            'reference' => 'FTA-1',
            'validation_status' => 'valid',
            'warnings' => ['check totals'],
            'errors' => [],
        ]);

        $response = $this->withToken($this->token)->getJson("/api/compliance/ae/status/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('data.submission_id', $submission->id)
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.fta_ref', 'FTA-1')
            ->assertJsonPath('data.warnings', ['check totals']);

        $this->assertSame(
            ['submission_id', 'status', 'fta_ref', 'validation_status', 'warnings', 'errors', 'submitted_at', 'accepted_at'],
            array_keys($response->json('data'))
        );
    }

    public function test_rival_submission_not_found(): void
    {
        $theirs = $this->submission($this->rival, 'failed');

        $this->withToken($this->token)->getJson("/api/compliance/ae/status/{$theirs->id}")->assertNotFound();
        $this->withToken($this->token)->postJson("/api/compliance/ae/retry/{$theirs->id}")->assertNotFound();
    }

    private function submission(Organization $organization, string $status, array $attributes = []): FtaSubmission
    {
        $invoice = (new Invoice)->forceFill([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'AED',
            'buyer_name' => 'Buyer',
        ]);
        $invoice->save();

        $submission = (new FtaSubmission)->forceFill([
            'invoice_id' => $invoice->id,
            'org_id' => $organization->id,
            'status' => $status,
            ...$attributes,
        ]);
        $submission->save();

        return $submission;
    }
}
