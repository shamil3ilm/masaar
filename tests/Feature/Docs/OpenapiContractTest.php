<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The OpenAPI description says what docs/integration/API.md says about
 * retries: which routes take an Idempotency-Key, which answer a replay with
 * Idempotent-Replayed, and which may answer 207, 403, 429 and Retry-After.
 */
class OpenapiContractTest extends TestCase
{
    public function test_pipeline_submit_describes_its_answers(): void
    {
        $operation = $this->spec()['paths']['/api/v1/pipeline/submit']['post'];

        $this->assertContains('Idempotency-Key', array_column($operation['parameters'] ?? [], 'name'));

        foreach (['201', '207', '401', '403', '422', '429'] as $status) {
            $this->assertArrayHasKey($status, $operation['responses'], $status);
        }

        $this->assertArrayHasKey('Retry-After', $operation['responses']['429']['headers']);

        // Busy: the device or the records, to send again after Retry-After;
        // and a number the organization has used already.
        $this->assertArrayHasKey('Retry-After', $operation['responses']['503']['headers']);
        $this->assertStringContainsString('VAL_026', $operation['responses']['409']['description']);

        // A retry is answered with the status the invoice's state earns now.
        foreach (['201', '202', '207'] as $status) {
            $this->assertArrayHasKey('Idempotent-Replayed', $operation['responses'][$status]['headers'] ?? [], $status);
        }
    }

    public function test_replaying_routes_declare_the_header(): void
    {
        foreach ([['/api/v1/invoices', 'post'], ['/api/v1/compliance/submit/{invoiceId}', 'post'], ['/api/v1/compliance/generate/{invoiceId}', 'post'], ['/api/v1/invoices/splits', 'post']] as [$path, $method]) {
            $this->assertArrayHasKey('Idempotent-Replayed', $this->spec()['paths'][$path][$method]['responses']['200']['headers'] ?? [], $path);
        }
    }

    public function test_acting_routes_take_the_key(): void
    {
        foreach ([['/api/v1/schedules', '201'], ['/api/v1/webhooks', '201'], ['/api/v1/webhooks/{webhook}/rotate-secret', '200'], ['/api/v1/invoices/splits/{split}/settle', '200'], ['/api/v1/compliance/ae/invoices', '201']] as [$path, $status]) {
            $operation = $this->spec()['paths'][$path]['post'];

            $this->assertContains('Idempotency-Key', array_column($operation['parameters'] ?? [], 'name'), $path);
            $this->assertArrayHasKey('Idempotent-Replayed', $operation['responses'][$status]['headers'] ?? [], $path);
            $this->assertArrayHasKey('409', $operation['responses'], $path);
        }
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return Yaml::parseFile(base_path('docs/openapi.yaml'));
    }
}
