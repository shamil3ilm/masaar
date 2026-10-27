<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order an ERP walks its invoices and Peppol documents in to keep its
 * copy in step (Keyset): the organization's, by when each last changed,
 * then by id. Without it every page of the walk sorts the organization's
 * whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['org_id', 'updated_at', 'id'], 'invoices_sync_order_index');
        });

        Schema::table('peppol_documents', function (Blueprint $table) {
            $table->index(['org_id', 'updated_at', 'id'], 'peppol_documents_sync_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_sync_order_index');
        });

        Schema::table('peppol_documents', function (Blueprint $table) {
            $table->dropIndex('peppol_documents_sync_order_index');
        });
    }
};
