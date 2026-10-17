<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\InvoiceValidator;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Smoke tests: verify all renamed classes are resolvable.
 * Catches namespace/autoload issues after the Fatoora rename.
 */
class SmokeTest extends TestCase
{
    // ── Fatoora (KSA) ─────────────────────────────────────────────

    public function test_fatoora_submission_service_resolves(): void
    {
        $this->assertInstanceOf(
            Submitter::class,
            $this->app->make(Submitter::class)
        );
    }

    public function test_fatoora_validator_resolves(): void
    {
        $this->assertInstanceOf(
            InvoiceValidator::class,
            $this->app->make(InvoiceValidator::class)
        );
    }

    public function test_fatoora_compliance_service_resolves(): void
    {
        $this->assertInstanceOf(
            DocumentBuilder::class,
            $this->app->make(DocumentBuilder::class)
        );
    }

    // ── Routes ─────────────────────────────────────────────────────

    public function test_compliance_sa_routes_registered(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $saPaths = $routes->filter(fn ($r) => str_contains($r->uri(), 'compliance/sa'));
        $this->assertGreaterThanOrEqual(3, $saPaths->count(), 'Expected at least 3 /compliance/sa routes');
    }

    // ── Config ─────────────────────────────────────────────────────

    public function test_fatoora_config_loads(): void
    {
        $this->assertNotNull(config('fatoora.environment'), 'config/fatoora.php must define environment key');
    }

    public function test_zatca_config_key_is_gone(): void
    {
        $this->assertNull(config('zatca'), 'config/zatca.php should no longer exist');
    }

    public function test_uae_fta_config_key_is_gone(): void
    {
        $this->assertNull(config('uae-fta'), 'config/uae-fta.php should no longer exist');
    }
}
