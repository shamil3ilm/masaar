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
    }

    public function test_replaying_routes_declare_the_header(): void
    {
        foreach ([['/api/v1/invoices', 'post'], ['/api/v1/compliance/submit/{invoiceId}', 'post'], ['/api/v1/compliance/generate/{invoiceId}', 'post'], ['/api/v1/invoices/splits', 'post']] as [$path, $method]) {
            $this->assertArrayHasKey('Idempotent-Replayed', $this->spec()['paths'][$path][$method]['responses']['200']['headers'] ?? [], $path);
        }
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return Yaml::parseFile(base_path('docs/openapi.yaml'));
    }
}
