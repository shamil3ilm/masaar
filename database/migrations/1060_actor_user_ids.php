<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who last changed a setting (settings.updated_by) and who set a feature
 * switch (organization_feature_overrides.set_by) as user ids with a key to
 * users, as every other actor column is. Both held the admin's email: a
 * copy that goes stale when the address changes and outlives the account.
 *
 * Each email is mapped to its account; one with no account any more leaves
 * the actor unknown. A value that is already a user's id is kept. The
 * screens look the email up from the id (Settings, JurisdictionAvailability,
 * FeatureOverrides).
 */
return new class extends Migration
{
    /** table => actor column */
    private const COLUMNS = [
        'settings' => 'updated_by',
        'organization_feature_overrides' => 'set_by',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $name => $column) {
            $this->map($name, $column, fn (string $value): ?string => DB::table('users')->where('id', $value)->exists()
                ? $value
                : DB::table('users')->where('email', $value)->value('id'));

            Schema::table($name, function (Blueprint $table) use ($column) {
                $table->uuid($column)->nullable()->change();
            });

            Schema::table($name, function (Blueprint $table) use ($column) {
                $table->foreign($column)->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $name => $column) {
            Schema::table($name, function (Blueprint $table) use ($column) {
                $table->dropForeign([$column]);
            });

            Schema::table($name, function (Blueprint $table) use ($column) {
                $table->string($column, 255)->nullable()->change();
            });

            $this->map($name, $column, fn (string $value): ?string => DB::table('users')->where('id', $value)->value('email'));
        }
    }

    /**
     * @param  callable(string): ?string  $to
     */
    private function map(string $name, string $column, callable $to): void
    {
        $values = DB::table($name)->whereNotNull($column)->distinct()->pluck($column);

        foreach ($values as $value) {
            DB::table($name)->where($column, $value)->update([$column => $to((string) $value)]);
        }
    }
};
