<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Which language a bilingual invoice prints first, out of its column and its
 * console setting and into the customizations store as printing.language_order:
 * each organization's own choice, and the platform's when the console had
 * turned Arabic first off. A null column followed the platform, so it moves
 * as no row; the shipped default stays Arabic first.
 */
return new class extends Migration
{
    private const KEY = 'printing.language_order';

    private const SETTING = 'invoices.arabic_first';

    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach (DB::table('organizations')->whereIn('print_language_order', ['arabic_first', 'english_first'])->get(['id', 'print_language_order']) as $organization) {
            $rows[] = $this->row('organization', 'organization:'.$organization->id, $organization->id, $organization->print_language_order, $now);
        }

        $setting = DB::table('settings')->where('key', self::SETTING)->value('value');

        if ($setting !== null && json_decode((string) $setting, true) === false) {
            $rows[] = $this->row('platform', 'platform', null, 'english_first', $now);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('customizations')->insert($chunk);
        }

        DB::table('settings')->where('key', self::SETTING)->delete();

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('print_language_order');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('print_language_order', 16)->nullable();
        });

        foreach (DB::table('customizations')->where('key', self::KEY)->whereNull('deleted_at')->get() as $row) {
            $value = json_decode((string) $row->value, true);

            match ($row->level) {
                'organization' => DB::table('organizations')->where('id', $row->org_id)->update(['print_language_order' => $value]),
                'platform' => DB::table('settings')->updateOrInsert(['key' => self::SETTING], ['value' => json_encode($value !== 'english_first'), 'updated_at' => now()]),
                default => null,
            };
        }

        DB::table('customizations')->where('key', self::KEY)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $level, string $holder, ?string $organizationId, string $value, mixed $now): array
    {
        return [
            'id' => (string) Str::uuid(),
            'key' => self::KEY,
            'level' => $level,
            'holder' => $holder,
            'org_id' => $organizationId,
            'branch_id' => null,
            'user_id' => null,
            'value' => json_encode($value),
            'locked' => false,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
