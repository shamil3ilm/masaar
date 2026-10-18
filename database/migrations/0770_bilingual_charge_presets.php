<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The platform's common charges in both languages.
 *
 * A charge's reason is printed on the invoice, and on a Saudi invoice every
 * text a person reads is in Arabic, a translation beside it allowed (ZATCA
 * E-Invoicing Detailed Guidelines p63). The platform's own list was English
 * alone, so a charge picked from it was refused on a Saudi invoice. Only
 * rows still holding the shipped English are changed; an organization's or a
 * person's own entries are theirs.
 */
return new class extends Migration
{
    /** key => [shipped English, both languages] */
    private const REASONS = [
        'delivery' => ['Delivery', 'Delivery / رسوم التوصيل'],
        'service' => ['Service charge', 'Service charge / رسوم الخدمة'],
        'packaging' => ['Packaging', 'Packaging / رسوم التغليف'],
        'installation' => ['Installation', 'Installation / رسوم التركيب'],
        'other' => ['Other charge', 'Other charge / رسوم أخرى'],
    ];

    public function up(): void
    {
        foreach (self::REASONS as $key => [$english, $both]) {
            $this->platform($key)->where('reason', $english)->update(['reason' => $both, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::REASONS as $key => [$english, $both]) {
            $this->platform($key)->where('reason', $both)->update(['reason' => $english, 'updated_at' => now()]);
        }
    }

    private function platform(string $key): Builder
    {
        return DB::table('charge_presets')->whereNull('org_id')->whereNull('user_id')->where('key', $key);
    }
};
