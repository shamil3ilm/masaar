<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the buyer's own paperwork calls the sale: its purchase order (BT-13),
 * the contract it falls under (BT-12) and the reference it asked to be
 * quoted (BT-10). A buyer's accounts payable matches an invoice by these, and
 * a government buyer will not pay one without them.
 *
 * 127 characters: ZATCA's limit for the purchase order and the contract
 * (BR-KSA-F-06-C3, -C5), and the buyer reference kept to the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('order_reference', 127)->nullable()->after('billing_ref');
            $table->string('contract_reference', 127)->nullable()->after('order_reference');
            $table->string('buyer_reference', 127)->nullable()->after('contract_reference');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['order_reference', 'contract_reference', 'buyer_reference']);
        });
    }
};
