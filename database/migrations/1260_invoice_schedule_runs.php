<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One period of a schedule, and the invoice made for it.
 *
 * The unique (schedule_id, period) is what makes the runner idempotent: a
 * period is claimed by inserting its row, so a second runner - the same
 * hour run twice, or two replicas at once - finds it taken and moves on,
 * and no period is ever invoiced twice. A failed run keeps its row and is
 * tried again at next_attempt_at; a skipped period is a row too, so the
 * runner passes it by.
 *
 * An entity (class a) under its schedule: its status moves, and a person
 * may make one (a skip), so it keeps who made and last changed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_schedule_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('schedule_id');
            // The period's index from the schedule's first, 0-based.
            $table->unsignedInteger('period');
            $table->date('period_start');
            $table->date('period_end');
            // pending, drafted, issued, failed or skipped.
            $table->string('status', 16)->default('pending');
            $table->uuid('invoice_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('schedule_id')->references('id')->on('invoice_schedules')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();

            foreach (['created_by', 'updated_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }

            $table->unique(['schedule_id', 'period'], 'invoice_schedule_runs_period_unique');
            // What the runner retries: the failed and unfinished, when due.
            $table->index(['status', 'next_attempt_at'], 'invoice_schedule_runs_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_schedule_runs');
    }
};
