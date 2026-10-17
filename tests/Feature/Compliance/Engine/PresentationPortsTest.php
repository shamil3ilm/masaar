<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Engine;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Contracts\Presentation;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every country answers the presentation, reports, dashboard and setup
 * ports, with views that exist in its own folder of views. Read from the
 * registry, so a new country is held to the same the day it registers.
 *
 * What each country shows is pinned by its own tests; this pins only that
 * every port is answered, and in the shape shared screens read.
 */
class PresentationPortsTest extends TestCase
{
    use RefreshDatabase;

    private const STEP_KEYS = ['detail', 'done', 'key', 'member', 'required', 'route', 'title'];

    public function test_views_exist_in_country_folder(): void
    {
        foreach ($this->modules() as $code => $module) {
            $organization = $this->organization($code);
            $presentation = $module->presentation();
            $page = $presentation->invoicePage($this->draft($organization));

            $views = array_filter([
                $presentation->navigation(Presentation::NAV_MAIN),
                $presentation->navigation(Presentation::NAV_ADMIN),
                $page->heading,
                $page->actions,
                $page->panel,
                $module->dashboard()->portal($organization)->view,
                $module->reports()->page((string) $organization->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now())->view,
            ]);

            foreach ($views as $view) {
                $this->assertStringStartsWith($this->folder($module), $view, "{$code}: {$view}");
                $this->assertTrue(View::exists($view), "{$code}: {$view} does not exist");
            }
        }
    }

    public function test_setup_steps_have_every_field(): void
    {
        foreach ($this->modules() as $code => $module) {
            $organization = $this->organization($code);
            $setup = $module->setup();
            $steps = [$setup->company($organization), ...$setup->onboarding($organization), $setup->firstInvoice($organization)];

            foreach ($steps as $step) {
                $keys = array_keys($step);
                sort($keys);

                $this->assertSame(self::STEP_KEYS, $keys, "{$code}: {$step['key']}");
                $this->assertNotSame('', $step['title'], "{$code}: {$step['key']}");
            }
        }
    }

    public function test_draft_is_never_given_out(): void
    {
        foreach ($this->modules() as $code => $module) {
            $draft = $this->draft($this->organization($code));

            $this->assertFalse($module->presentation()->invoicePage($draft)->shareable, $code);

            try {
                $module->presentation()->assertShareable($draft);
                $this->fail("{$code} would hand over a draft.");
            } catch (DomainException $e) {
                $this->assertNotSame('', $e->getMessage(), $code);
            }
        }
    }

    public function test_dashboard_health_has_a_verdict(): void
    {
        foreach ($this->modules() as $code => $module) {
            $health = $module->dashboard()->health((string) $this->organization($code)->id);

            $this->assertSame(['checks', 'status'], array_keys($health), $code);
            $this->assertContains($health['status'], ['healthy', 'warning', 'critical'], $code);
            $this->assertNotSame('', $module->dashboard()->heading(), $code);
            $this->assertNotSame('', $module->presentation()->tagline(), $code);
        }
    }

    /**
     * @return array<string, Jurisdiction>
     */
    private function modules(): array
    {
        $registry = app(JurisdictionRegistry::class);
        $modules = [];

        foreach ($registry->registered() as $code) {
            $modules[$code] = $registry->get($code);
        }

        $this->assertNotSame([], $modules, 'No country is registered.');

        return $modules;
    }

    /** countries.<the module's folder, in kebab case>. */
    private function folder(Jurisdiction $module): string
    {
        return 'countries.'.Str::kebab(class_basename(Str::beforeLast($module::class, '\\'))).'.';
    }

    private function organization(string $code): Organization
    {
        return Organization::create(['name' => 'Acme '.$code, 'country' => $code, 'status' => Organization::STATUS_ACTIVE]);
    }

    private function draft(Organization $organization): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'type' => 'standard',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }
}
