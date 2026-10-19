<?php

declare(strict_types=1);

namespace Tests\Feature\Interface;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The date and time fields keep the browser's own input as the value, so a
 * form sends year-month-day whether or not the calendar in front of it runs,
 * and a Saudi organization's pages name the Hijri calendar the picker shows
 * beside the Gregorian.
 */
class DateFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_dates_keep_a_native_input(): void
    {
        $html = Blade::render('<x-ui.date-input name="issue_date" value="2026-10-14" min="2026-01-01" required />');

        $this->assertStringContainsString('data-date-field', $html);
        $this->assertMatchesRegularExpression('/<input type="date"[^>]*name="issue_date"[^>]*value="2026-10-14"[^>]*min="2026-01-01"[^>]*required/', $html);
    }

    public function test_times_keep_a_native_input(): void
    {
        $html = Blade::render('<x-ui.time-input name="at" value="13:30" step="900" />');

        $this->assertStringContainsString('data-time-field', $html);
        $this->assertMatchesRegularExpression('/<input type="time"[^>]*name="at"[^>]*value="13:30"[^>]*step="900"/', $html);
    }

    public function test_each_country_names_its_second_calendar(): void
    {
        $resolver = app(JurisdictionResolver::class);

        $this->assertSame('islamic-umalqura', $resolver->forRules(new Organization(['country' => 'SA']))->presentation()->alternateCalendar());
        $this->assertNull($resolver->forRules(new Organization(['country' => 'AE']))->presentation()->alternateCalendar());
    }

    public function test_saudi_pages_name_hijri(): void
    {
        $organization = Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003']);
        $member = User::factory()->create();
        $member->organizations()->attach($organization->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($member)->withSession(['portal_org_id' => $organization->id])
            ->get(route('portal.invoices.create'))
            ->assertOk()
            ->assertSee('data-alt-calendar="islamic-umalqura"', false)
            ->assertSee('data-date-field', false);
    }
}
