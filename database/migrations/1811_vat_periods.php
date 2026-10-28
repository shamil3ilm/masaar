<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A VAT period the organization has filed: its dates, the day it was
 * filed, and the return's figures as they were then, so a later redraft
 * can be compared with what was filed. A filed period is closed: what
 * would change it goes to the next open period or is refused (VatPeriods).
 *
 * A compliance record (class c): never deleted. Reopening it - an owner's
 * decision, with a reason - is its status changing, with who and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('filed_on');
            $table->json('figures');
            $table->string('status', 20)->default('filed');
            $table->string('reopen_reason', 500)->nullable();
            $table->uuid('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'starts_on']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('reopened_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_periods');
    }
};
