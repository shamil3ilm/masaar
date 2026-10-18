<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Services\ChargePresets;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The common charges an invoice form offers: the platform's list, which an
 * organization and then each person in it can change, add to and hide from.
 */
class ChargePresetsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $member;

    private ChargePresets $presets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->member = User::factory()->create();
        $this->presets = app(ChargePresets::class);
    }

    /** UNCL 7161 codes both ZATCA and PINT AE accept. */
    public function test_the_platform_list_comes_first(): void
    {
        $this->assertSame(
            ['delivery' => 'DL', 'service' => 'ADR', 'packaging' => 'PC', 'installation' => 'IAA', 'other' => 'ABK'],
            array_column($this->presets->for($this->acme->id), 'reason_code', 'key'),
        );
    }

    /**
     * Each reason in both languages, so a charge picked from the platform's
     * list is accepted on an invoice that must be in Arabic (ZATCA E-Invoicing
     * Detailed Guidelines p63).
     */
    public function test_the_platform_list_is_bilingual(): void
    {
        $this->assertSame(
            [
                'delivery' => 'Delivery / رسوم التوصيل',
                'service' => 'Service charge / رسوم الخدمة',
                'packaging' => 'Packaging / رسوم التغليف',
                'installation' => 'Installation / رسوم التركيب',
                'other' => 'Other charge / رسوم أخرى',
            ],
            array_column($this->presets->for($this->acme->id), 'reason', 'key'),
        );
    }

    public function test_an_organization_overrides_and_adds(): void
    {
        $this->presets->save($this->acme->id, null, ['key' => 'service', 'reason' => 'Service charge', 'reason_code' => 'ADR', 'percent' => '12.5']);
        $this->presets->save($this->acme->id, null, ['key' => 'corkage', 'reason' => 'Corkage', 'reason_code' => 'ABK', 'amount' => '50.00']);

        $list = collect($this->presets->for($this->acme->id))->keyBy('key');

        $this->assertSame('12.50', (string) $list['service']['percent']);
        $this->assertSame('50.00', (string) $list['corkage']['amount']);
        $this->assertSame('corkage', $list->keys()->last());

        // Another organization still has the platform's.
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $this->assertNull(collect($this->presets->for($rival->id))->keyBy('key')['service']['percent']);
    }

    public function test_a_person_overrides_the_organization(): void
    {
        $this->presets->save($this->acme->id, null, ['key' => 'delivery', 'reason' => 'Delivery', 'reason_code' => 'DL', 'amount' => '20.00']);
        $this->presets->save($this->acme->id, $this->member->id, ['key' => 'delivery', 'reason' => 'Express delivery', 'reason_code' => 'AAT', 'amount' => '35.00']);

        $mine = collect($this->presets->for($this->acme->id, $this->member->id))->keyBy('key');
        $everyone = collect($this->presets->for($this->acme->id))->keyBy('key');

        $this->assertSame(['AAT', '35.00'], [$mine['delivery']['reason_code'], (string) $mine['delivery']['amount']]);
        $this->assertSame(['DL', '20.00'], [$everyone['delivery']['reason_code'], (string) $everyone['delivery']['amount']]);
    }

    /** A level hides an entry from above, and its own entry is simply removed. */
    public function test_a_level_hides_an_entry(): void
    {
        $this->presets->hide($this->acme->id, null, 'installation');
        $this->presets->save($this->acme->id, $this->member->id, ['key' => 'tip', 'reason' => 'Tip', 'reason_code' => 'ABK']);
        $this->presets->hide($this->acme->id, $this->member->id, 'packaging');

        $this->assertNotContains('installation', array_column($this->presets->for($this->acme->id), 'key'));
        $this->assertSame(['delivery', 'service', 'other', 'tip'], array_column($this->presets->for($this->acme->id, $this->member->id), 'key'));

        $this->presets->hide($this->acme->id, $this->member->id, 'tip');
        $this->assertNotContains('tip', array_column($this->presets->for($this->acme->id, $this->member->id), 'key'));

        // Saved again, a hidden entry is back.
        $this->presets->save($this->acme->id, null, ['key' => 'installation', 'reason' => 'Installation', 'reason_code' => 'IAA']);
        $this->assertContains('installation', array_column($this->presets->for($this->acme->id), 'key'));
    }
}
