<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the platform pays: its hosting, its e-invoicing service provider, its
 * card gateway, its subscriptions and anyone else it buys from
 * (Admin › Finance › Suppliers).
 *
 * country decides whether a supplier is resident where the platform is
 * taxed, which is what makes its services reverse charge (Expenses). The
 * IBAN is checked as ISO 13616 defines it (Iban) and kept without spaces.
 * category is a key of the expense categories (finance.expense_categories),
 * the one its expenses start with.
 *
 * Platform-wide (no org_id): the platform's own costs, not a customer's.
 * Class (b): a supplier no expense names can be removed and restored; one
 * that is named is only archived - out of the pickers, still on the
 * expenses that name it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('country', 2);
            $table->string('vat_number', 30)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('currency', 3);
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('bank_name', 120)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('swift', 11)->nullable();
            $table->string('category', 40)->nullable();
            $table->string('notes', 2000)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->uuid('archived_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');

            foreach (['archived_by', 'created_by', 'updated_by', 'deleted_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
