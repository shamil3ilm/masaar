<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer's next invoice starts with beyond its name and address:
 * the identifier a buyer without a VAT number is known by, and the register
 * it is from (BT-46, BT-46-1), and the terms it is usually paid on.
 *
 * A business buyer below the VAT threshold is invoiced by its commercial
 * registration, and a citizen buying private healthcare by national ID
 * (BR-KSA-49): without these the cashier retyped them on every invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('buyer_id', 50)->nullable()->after('vat_number');
            $table->string('buyer_id_scheme', 3)->nullable()->after('buyer_id');
            $table->string('payment_terms', 1000)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['buyer_id', 'buyer_id_scheme', 'payment_terms']);
        });
    }
};
