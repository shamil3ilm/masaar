<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One customer's documents, for what Books Receivables reads per customer:
 * a statement, and the bad-debt limit on what the customer owes. The
 * organization's documents of a kind by date already have theirs
 * (invoices_org_document_date_idx).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['org_id', 'customer_id'], 'invoices_org_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_org_customer_idx');
        });
    }
};
