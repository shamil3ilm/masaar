<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A removed customer's VAT number, a removed product's SKU, a removed
 * compliance profile's jurisdiction and a removed record's external id can
 * be used again.
 *
 * A unique index that includes deleted_at would not do it: two rows that
 * are not removed both have a null there, and nulls are not equal to each
 * other, so it would allow duplicates of exactly the rows that matter.
 * not_deleted is a generated column that is 1 while the row is kept and
 * null once it is removed: kept rows collide, removed ones never do. MySQL
 * and SQLite both compute it from deleted_at, so nothing writes it.
 *
 * The new index is made before the old one goes, so the rule holds
 * throughout and org_id keeps an index under its foreign key. On MySQL 8 a
 * virtual column is an instant add, and each index is built in place with
 * writes allowed.
 */
return new class extends Migration
{
    private const KEPT = 'case when deleted_at is null then 1 end';

    /** @var array<string, array{replace: array<string, array{string, list<string>}>, add: array<string, list<string>>}> */
    private const INDEXES = [
        'customers' => [
            'replace' => ['customers_org_id_vat_number_unique' => ['customers_kept_vat_number_unique', ['org_id', 'vat_number']]],
            'add' => ['customers_external_id_unique' => ['org_id', 'license_id', 'external_id']],
        ],
        'products' => [
            'replace' => ['products_org_id_sku_unique' => ['products_kept_sku_unique', ['org_id', 'sku']]],
            'add' => ['products_external_id_unique' => ['org_id', 'license_id', 'external_id']],
        ],
        'compliance_profiles' => [
            'replace' => ['compliance_profiles_organization_id_jurisdiction_unique' => ['compliance_profiles_kept_jurisdiction_unique', ['org_id', 'jurisdiction']]],
            'add' => [],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $indexes) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedTinyInteger('not_deleted')->nullable()->virtualAs(self::KEPT);
            });

            Schema::table($name, function (Blueprint $table) use ($indexes) {
                foreach ($indexes['replace'] as [$index, $columns]) {
                    $table->unique([...$columns, 'not_deleted'], $index);
                }

                foreach ($indexes['add'] as $index => $columns) {
                    $table->unique([...$columns, 'not_deleted'], $index);
                }
            });

            Schema::table($name, function (Blueprint $table) use ($indexes) {
                foreach (array_keys($indexes['replace']) as $old) {
                    $table->dropUnique($old);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => $indexes) {
            Schema::table($name, function (Blueprint $table) use ($indexes) {
                foreach ($indexes['replace'] as $old => [, $columns]) {
                    $table->unique($columns, $old);
                }
            });

            Schema::table($name, function (Blueprint $table) use ($indexes) {
                foreach ($indexes['replace'] as [$index]) {
                    $table->dropUnique($index);
                }

                foreach (array_keys($indexes['add']) as $index) {
                    $table->dropUnique($index);
                }
            });

            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('not_deleted');
            });
        }
    }
};
