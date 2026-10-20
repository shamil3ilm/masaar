<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cost the platform expects every month or every year: hosting, its
 * service providers' fees, subscriptions (Admin › Finance › Recurring
 * costs).
 *
 * next_due_on is when the next bill is expected. A daily run
 * (billing:expect-expenses) drafts an expense for it on that day with the
 * expected amounts, and moves next_due_on on by a month or a year; a draft
 * still not recorded some days later is a missing bill, which the console
 * shows (finance.missing_bill_days).
 *
 * Platform-wide (no org_id). Class (b): removed and restored; paused with
 * active off. The expenses it drafted keep their own figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id');
            $table->string('description', 300);
            $table->string('category', 40);
            $table->string('cost_centre', 40)->nullable();
            $table->string('currency', 3);
            $table->decimal('net', 14, 2);
            $table->decimal('vat', 14, 2)->default(0);
            $table->string('vat_treatment', 20);
            $table->string('frequency', 10);
            $table->date('next_due_on');
            $table->boolean('active')->default(true);
            $table->date('last_drafted_on')->nullable();
            $table->string('notes', 2000)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'next_due_on']);
            $table->foreign('supplier_id')->references('id')->on('suppliers');

            foreach (['created_by', 'updated_by', 'deleted_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_expenses');
    }
};
