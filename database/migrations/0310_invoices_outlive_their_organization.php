<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting an organization that has invoices is refused.
 *
 * invoices.org_id cascaded, so deleting the organization row removed every
 * invoice with it, below the model guard that refuses to delete an issued
 * one - and the taxpayer must keep them for six years. Restricting this one
 * key is enough: the delete fails as a whole, so none of the other tables
 * that cascade from organizations lose anything either.
 */
return new class extends Migration
{
    private const KEY = 'invoices_organization_id_foreign';

    public function up(): void
    {
        $this->replace(fn (Blueprint $table) => $table->foreign('org_id', self::KEY)
            ->references('id')->on('organizations')->restrictOnDelete());
    }

    public function down(): void
    {
        $this->replace(fn (Blueprint $table) => $table->foreign('org_id', self::KEY)
            ->references('id')->on('organizations')->cascadeOnDelete());
    }

    /**
     * The key is named after the column it replaced, so MySQL drops it by
     * that name. SQLite, which the tests run on, can only drop by column.
     */
    private function replace(Closure $add): void
    {
        Schema::table('invoices', function (Blueprint $table) use ($add) {
            $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite' ? ['org_id'] : self::KEY);
            $add($table);
        });
    }
};
