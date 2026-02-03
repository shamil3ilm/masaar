<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'version',
                'timestamp',
            ])
            ->assertJson([
                'status' => 'ok',
            ]);
    }

    public function test_health_endpoint_returns_valid_timestamp(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();

        $data = $response->json();
        $this->assertNotEmpty($data['timestamp']);

        // Verify timestamp is valid ISO 8601 (Laravel's toISOString includes milliseconds)
        $timestamp = strtotime($data['timestamp']);
        $this->assertNotFalse($timestamp, 'Timestamp should be parseable');
        $this->assertGreaterThan(0, $timestamp);
    }

    public function test_health_endpoint_returns_version(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();

        $data = $response->json();
        $this->assertNotEmpty($data['version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $data['version']);
    }
}
