<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The platform's own list of common charges, which every organization
 * starts with. UNCL 7161 codes both ZATCA (BR-KSA-CL-06) and PINT AE
 * (IBR-CL-20) accept: DL delivery, ADR other services, PC packing, IAA
 * installation, ABK miscellaneous. No amount and no tax category: each is
 * priced on the invoice, at the invoice's standard rate unless changed.
 */
return new class extends Migration
{
    private const PRESETS = [
        'delivery' => ['Delivery', 'DL'],
        'service' => ['Service charge', 'ADR'],
        'packaging' => ['Packaging', 'PC'],
        'installation' => ['Installation', 'IAA'],
        'other' => ['Other charge', 'ABK'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (array_keys(self::PRESETS) as $position => $key) {
            [$reason, $code] = self::PRESETS[$key];

            DB::table('charge_presets')->insert([
                'id' => (string) Str::uuid(),
                'org_id' => null,
                'user_id' => null,
                'key' => $key,
                'position' => $position + 1,
                'reason' => $reason,
                'reason_code' => $code,
                'disabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('charge_presets')->whereNull('org_id')->whereNull('user_id')->whereIn('key', array_keys(self::PRESETS))->delete();
    }
};
