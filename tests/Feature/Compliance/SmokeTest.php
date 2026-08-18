<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\FatooraComplianceService;
use App\Domains\Compliance\Fatoora\Services\FatooraSubmissionService;
use App\Domains\Compliance\Fatoora\Services\FatooraValidator;
use App\Domains\Compliance\FTA\Services\FtaService;
use App\Domains\Compliance\FTA\Services\FtaValidator;
use App\Domains\Compliance\FTA\Services\FtaXmlBuilder;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Smoke tests: verify all renamed classes are resolvable.
 * Catches namespace/autoload issues after the Fatoora/FTA rename.
 */
class SmokeTest extends TestCase
{
    // ── Fatoora (KSA) ─────────────────────────────────────────────

    public function test_fatoora_submission_service_resolves(): void
    {
        $this->assertInstanceOf(
            FatooraSubmissionService::class,
            $this->app->make(FatooraSubmissionService::class)
        );
    }

    public function test_fatoora_validator_resolves(): void
    {
        $this->assertInstanceOf(
            FatooraValidator::class,
            $this->app->make(FatooraValidator::class)
        );
    }

    public function test_fatoora_compliance_service_resolves(): void
    {
        $this->assertInstanceOf(
            FatooraComplianceService::class,
            $this->app->make(FatooraComplianceService::class)
        );
    }

    // ── FTA (UAE) ──────────────────────────────────────────────────

    public function test_fta_service_resolves(): void
    {
        $this->assertInstanceOf(
            FtaService::class,
            $this->app->make(FtaService::class)
        );
    }

    public function test_fta_validator_resolves(): void
    {
        $this->assertInstanceOf(
            FtaValidator::class,
            $this->app->make(FtaValidator::class)
        );
    }

    // ── PINT AE spec ───────────────────────────────────────────────

    public function test_fta_xml_builder_uses_pint_ae_customization_id(): void
    {
        $ref = new \ReflectionClass(FtaXmlBuilder::class);
        $this->assertSame(
            'urn:peppol:pint:billing-1@ae-1',
            $ref->getConstant('CUSTOMIZATION')
        );
    }

    public function test_fta_xml_builder_uses_pint_ae_profile_id(): void
    {
        $ref = new \ReflectionClass(FtaXmlBuilder::class);
        $this->assertSame(
            'urn:peppol:bis:billing',
            $ref->getConstant('PROFILE_ID')
        );
    }

    // ── Routes ─────────────────────────────────────────────────────

    public function test_compliance_sa_routes_registered(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $saPaths = $routes->filter(fn ($r) => str_contains($r->uri(), 'compliance/sa'));
        $this->assertGreaterThanOrEqual(3, $saPaths->count(), 'Expected at least 3 /compliance/sa routes');
    }

    public function test_compliance_ae_routes_registered(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $aePaths = $routes->filter(fn ($r) => str_contains($r->uri(), 'compliance/ae'));
        $this->assertGreaterThanOrEqual(3, $aePaths->count(), 'Expected at least 3 /compliance/ae routes');
    }

    // ── Config ─────────────────────────────────────────────────────

    public function test_fatoora_config_loads(): void
    {
        $this->assertNotNull(config('fatoora.environment'), 'config/fatoora.php must define environment key');
    }

    public function test_fta_config_loads(): void
    {
        $this->assertNotNull(config('fta.environment'), 'config/fta.php must define environment key');
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
