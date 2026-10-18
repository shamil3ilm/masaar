<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The common charges an invoice form offers, out of their own table and into
 * the customizations store as one list per level: the platform's, each
 * organization's and each person's in it. A disabled row was an entry hidden
 * from above, and stays one.
 *
 * The platform's list is the shipped default now (Catalogue::CHARGE_PRESETS),
 * so platform rows move only where they differ from it.
 */
return new class extends Migration
{
    private const FIELDS = ['reason', 'reason_code', 'percent', 'amount', 'tax_category', 'tax_rate'];

    public function up(): void
    {
        $lists = [];

        foreach (DB::table('charge_presets')->orderBy('position')->orderBy('key')->get() as $row) {
            $holder = match (true) {
                $row->org_id === null => 'platform',
                $row->user_id === null => 'organization:'.$row->org_id,
                default => 'member:'.$row->org_id.':'.$row->user_id,
            };

            $lists[$holder] ??= ['org_id' => $row->org_id, 'user_id' => $row->user_id, 'entries' => []];
            $lists[$holder]['entries'][] = $row->disabled ? ['key' => $row->key, 'hidden' => true] : $this->entry($row);
        }

        $shipped = [
            ['key' => 'delivery', 'reason' => 'Delivery', 'reason_code' => 'DL', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
            ['key' => 'service', 'reason' => 'Service charge', 'reason_code' => 'ADR', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
            ['key' => 'packaging', 'reason' => 'Packaging', 'reason_code' => 'PC', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
            ['key' => 'installation', 'reason' => 'Installation', 'reason_code' => 'IAA', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
            ['key' => 'other', 'reason' => 'Other charge', 'reason_code' => 'ABK', 'percent' => null, 'amount' => null, 'tax_category' => null, 'tax_rate' => null],
        ];

        $now = now();

        foreach ($lists as $holder => $list) {
            if ($holder === 'platform' && $list['entries'] === $shipped) {
                continue;
            }

            DB::table('customizations')->insert([
                'id' => (string) Str::uuid(),
                'key' => 'invoices.charge_presets',
                'level' => match (true) {
                    $holder === 'platform' => 'platform',
                    $list['user_id'] === null => 'organization',
                    default => 'user',
                },
                'holder' => $holder,
                'org_id' => $list['org_id'],
                'branch_id' => null,
                'user_id' => $list['user_id'],
                'value' => json_encode($list['entries']),
                'locked' => false,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::dropIfExists('charge_presets');
    }

    public function down(): void
    {
        Schema::create('charge_presets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('key', 50);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('reason', 255);
            $table->string('reason_code', 3);
            $table->decimal('percent', 5, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('tax_category', 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->boolean('disabled')->default(false);
            $table->timestamps();

            $table->index(['org_id', 'user_id']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $now = now();
        $rows = DB::table('customizations')->where('key', 'invoices.charge_presets')->get();

        // The shipped list was a set of platform rows before.
        if (! $rows->contains('holder', 'platform')) {
            $rows->push((object) ['org_id' => null, 'user_id' => null, 'value' => json_encode([
                ['key' => 'delivery', 'reason' => 'Delivery', 'reason_code' => 'DL'],
                ['key' => 'service', 'reason' => 'Service charge', 'reason_code' => 'ADR'],
                ['key' => 'packaging', 'reason' => 'Packaging', 'reason_code' => 'PC'],
                ['key' => 'installation', 'reason' => 'Installation', 'reason_code' => 'IAA'],
                ['key' => 'other', 'reason' => 'Other charge', 'reason_code' => 'ABK'],
            ])]);
        }

        foreach ($rows as $row) {
            foreach (json_decode((string) $row->value, true) as $position => $entry) {
                DB::table('charge_presets')->insert([
                    'id' => (string) Str::uuid(),
                    'org_id' => $row->org_id,
                    'user_id' => $row->user_id,
                    'key' => $entry['key'],
                    'position' => $position + 1,
                    'reason' => $entry['reason'] ?? $entry['key'],
                    'reason_code' => $entry['reason_code'] ?? 'ABK',
                    ...array_intersect_key($entry, array_flip(['percent', 'amount', 'tax_category', 'tax_rate'])),
                    'disabled' => ! empty($entry['hidden']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('customizations')->where('key', 'invoices.charge_presets')->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(object $row): array
    {
        $entry = ['key' => $row->key];

        foreach (self::FIELDS as $field) {
            $entry[$field] = $row->{$field} === null ? null : (string) $row->{$field};
        }

        return $entry;
    }
};
