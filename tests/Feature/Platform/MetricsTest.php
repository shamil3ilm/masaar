<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Compliance\Fatoora\Services\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a Prometheus scrape of /api/metrics actually contains.
 *
 * MetricsAccessTest decides who may scrape. This reads the body, because each
 * collector swallows its own failure: a query against a column that does not
 * exist removes a whole family of metrics and the endpoint still answers 200.
 */
class MetricsTest extends TestCase
{
    use PlatformRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['metrics.token' => null, 'metrics.allowed_ips' => []]);
    }

    public function test_scrape_reports_platform_basics(): void
    {
        $body = $this->scrape();

        $this->assertStringContainsString('# TYPE masaar_app_info gauge', $body);
        $this->assertMatchesRegularExpression('/^masaar_app_info\{version="[^"]*",environment="testing",php_version="'.preg_quote(PHP_VERSION, '/').'"\} 1$/m', $body);
        $this->assertStringContainsString("masaar_http_requests_total 0\n", $body);
        $this->assertStringContainsString("masaar_http_errors_total 0\n", $body);
        $this->assertStringContainsString("masaar_database_up 1\n", $body);
        $this->assertStringContainsString("masaar_cache_up 1\n", $body);
        $this->assertMatchesRegularExpression('/^masaar_database_latency_ms [0-9.]+$/m', $body);
    }

    public function test_invoice_metrics_span_every_tenant(): void
    {
        $this->invoice($this->organization('Acme'));
        $this->invoice($this->organization('Globex'));
        $this->invoice($this->organization('Initech'), ['created_at' => now()->subDays(3)]);

        $body = $this->scrape();

        $this->assertStringContainsString("# HELP masaar_invoices_total_draft Total invoices with status draft\n", $body);
        $this->assertStringContainsString("masaar_invoices_total_draft{status=\"draft\"} 3\n", $body);
        $this->assertStringContainsString("masaar_invoices_created_today 2\n", $body);
    }

    public function test_zatca_metrics_read_submission_state(): void
    {
        $acme = $this->organization('Acme');

        $this->submission($acme, 'cleared');
        $this->submission($acme, 'cleared');
        $this->submission($acme, 'rejected');
        $this->submission($acme, 'cleared', ['created_at' => now()->subDays(2)]);
        $this->queueItem($acme, 'pending');
        $this->queueItem($acme, 'failed');

        app(CircuitBreaker::class)->forceState('zatca_api', CircuitBreaker::STATE_OPEN, 'test', 'test');

        $body = $this->scrape();

        $this->assertStringContainsString("masaar_zatca_submissions_cleared{status=\"cleared\"} 2\n", $body);
        $this->assertStringContainsString("masaar_zatca_submissions_rejected{status=\"rejected\"} 1\n", $body);
        $this->assertStringContainsString("masaar_zatca_offline_queue_size 1\n", $body);
        $this->assertStringContainsString("masaar_zatca_circuit_breaker_open 1\n", $body);
        $this->assertStringContainsString("masaar_zatca_submission_duration_avg_ms 0\n", $body);
    }

    private function scrape(): string
    {
        $response = $this->get('/api/metrics')->assertOk();

        $this->assertSame('text/plain; version=0.0.4; charset=utf-8', $response->headers->get('Content-Type'));

        return (string) $response->getContent();
    }
}
