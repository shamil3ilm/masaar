<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customization: paid work for one customer, asked for in the portal.
 *
 * The customer's words are its ticket's; what is kept here is what support
 * agreed - the work as described to the customer, the price an admin enters
 * (there is no price list), whether it is billed at all, the features it
 * switches on for the organization once released (organization feature
 * overrides) and, once billed, the invoice that billed it.
 *
 * An entity (class a): the price and the description are agreed over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_customizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->unique();
            $table->uuid('org_id');
            $table->text('description')->nullable();
            // Excluding VAT, in the customer's currency.
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('billable')->default(true);
            // Access features switched on for the organization once released.
            $table->json('features')->nullable();
            // The invoice that billed it, and when: written by billing
            // through ChargeSource, which owns which table that invoice is in.
            $table->uuid('invoice_id')->nullable();
            $table->timestamp('billed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();

            foreach (['created_by', 'updated_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }

            // What billing asks for: an organization's unbilled work.
            $table->index(['org_id', 'invoice_id'], 'paid_customizations_unbilled_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_customizations');
    }
};
