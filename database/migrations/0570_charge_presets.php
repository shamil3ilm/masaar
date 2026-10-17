<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The charges an invoice form offers to pick: delivery, a service charge,
 * packaging. A list at three levels, each overriding the one above by key:
 * the platform's (org_id and user_id null), an organization's (org_id), and
 * one person's in it (org_id and user_id). An override can also hide an
 * entry above it (disabled).
 *
 * Kept to the one list so it can move into a general settings store later
 * as it is: a key, a scope and a value.
 */
return new class extends Migration
{
    public function up(): void
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
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_presets');
    }
};
