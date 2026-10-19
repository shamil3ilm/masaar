<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's invoices to a buyer that repeat: a school's terms, a
 * subscription, a monthly service.
 *
 * template is the invoice as the organization's country's rules take it
 * (InvoiceRules), less what each run decides - its dates and its number -
 * so every run is checked by the rules an invoice entered by hand is. A
 * period runs on its first day, on the organization's country's clock;
 * next_run_on is that civil date for the next period, next_period its
 * index, and both are null and the schedule completed once none is left.
 *
 * An entity (class a): changed, paused and cancelled, never removed - its
 * runs and their invoices name it. Who made and last changed it, and how it
 * arrived, as for an invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            // The branch whose address is the seller's on every run, as an
            // invoice's branch_id.
            $table->uuid('branch_id')->nullable();
            // The saved customer it bills, when there is one.
            $table->uuid('customer_id')->nullable();
            // The invoice it was made from, when it was.
            $table->uuid('source_invoice_id')->nullable();
            $table->string('name', 120);
            // weekly, monthly, quarterly, termly or yearly.
            $table->string('frequency', 16);
            // A termly schedule's terms: [{start_date, end_date}, ...].
            $table->json('terms')->nullable();
            $table->date('start_date');
            // The last period starts on or before end_date; occurrences is
            // how many periods there are. Neither: it runs until stopped.
            $table->date('end_date')->nullable();
            $table->unsignedInteger('occurrences')->nullable();
            // Issue each run, or leave it a draft for someone to review.
            $table->boolean('auto_issue')->default(false);
            // Days from issue to the due date, when the invoice states one.
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->json('template');
            // active, paused, cancelled or completed.
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('next_period')->nullable();
            $table->date('next_run_on')->nullable();
            // What stopped the last run, for the portal; null once one works.
            $table->string('last_error', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->string('created_via', 20)->nullable();
            $table->uuid('license_id')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('source_invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('license_id')->references('id')->on('licenses')->nullOnDelete();

            foreach (['created_by', 'updated_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }

            // What the runner asks: the active schedules due by a date.
            $table->index(['status', 'next_run_on'], 'invoice_schedules_due_index');
            $table->index(['org_id', 'status'], 'invoice_schedules_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_schedules');
    }
};
