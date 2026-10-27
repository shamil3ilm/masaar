<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Http\Responses\ApiResponse;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesInvoices;
use Tests\Fixtures\MatchesOpenapi;
use Tests\TestCase;

/**
 * Every error the API answers is what the OpenAPI Error schema says.
 *
 * The schema named code, message, details and category, while the envelope
 * also sent retryable on every error and retry_after and max_retries on
 * some, so a client generated from it dropped the one field that says
 * whether to try again. The error object is closed in the schema, so a
 * field added to the envelope fails here until it is described.
 *
 * Every error is built by ApiResponse::error, so it is checked three ways:
 * each status it maps, each compliance code, and real refusals sent
 * through the HTTP kernel to the routes that answer them.
 */
class ErrorSchemaTest extends TestCase
{
    use MakesInvoices;
    use MatchesOpenapi;
    use RefreshDatabase;

    public function test_every_status_matches_the_schema(): void
    {
        foreach ([400, 401, 403, 404, 405, 408, 409, 413, 419, 422, 429, 500, 502, 503, 504] as $status) {
            $plain = ApiResponse::error('Refused', $status);
            $full = ApiResponse::error('Refused', $status, ['field' => ['Wrong']], 'SOME_CODE', 'request', 30, 3);

            $this->assertMatchesSchema('Error', $plain->getData(true), "{$status} plain");
            $this->assertMatchesSchema('Error', $full->getData(true), "{$status} with every field");
        }
    }

    public function test_every_compliance_code_matches_the_schema(): void
    {
        foreach (ErrorCode::cases() as $code) {
            $response = ApiResponse::fromFatoora(new ComplianceException('Refused', $code, ['invoice_id' => 'x', 'retry_after' => 60]));

            $this->assertMatchesSchema('Error', $response->getData(true), $code->value);
        }
    }

    public function test_real_refusals_match_the_spec(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active']);
        License::create([
            'org_id' => $organization->id, 'api_key' => 'cp_test_errors', 'api_secret_hash' => License::hashSecret('errors-secret'),
            'organization_name' => 'Acme', 'contact_email' => 'ops@example.test', 'environment' => 'sandbox',
            'tier' => 'enterprise', 'status' => 'active', 'scopes' => ['invoice.read', 'invoice.submit'],
        ]);
        $headers = ['X-API-Key' => 'cp_test_errors', 'X-API-Secret' => 'errors-secret'];
        $draft = $this->makeInvoice($organization, 'INV-1');

        $cases = [
            // No credential.
            [$this->getJson('/api/v1/invoices'), '/api/v1/invoices', 'get', 401],
            // A scope the licence lacks.
            [$this->getJson('/api/v1/compliance/status/'.$draft->id, $headers), '/api/v1/compliance/status/{invoiceId}', 'get', 403],
            // Not the licence's organization's.
            [$this->getJson('/api/v1/invoices/00000000-0000-0000-0000-000000000000', $headers), '/api/v1/invoices/{invoice}', 'get', 404],
            // Validation.
            [$this->postJson('/api/v1/invoices', [], $headers), '/api/v1/invoices', 'post', 422],
            // A draft has no PDF yet.
            [$this->getJson('/api/v1/invoices/'.$draft->id.'/pdf', $headers), '/api/v1/invoices/{invoice}/pdf', 'get', 409],
        ];

        foreach ($cases as [$response, $path, $method, $status]) {
            $response->assertStatus($status);
            $this->assertResponseMatchesSpec($response, $path, $method);
        }
    }
}
