<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add ZATCA invoice type sub-flags to the invoices table.
 *
 * These boolean flags encode bits 3-7 of the ZATCA Invoice Type Code (BT-3):
 *   bit 3 = third party (B2B invoiced on behalf of another party)
 *   bit 4 = nominal (internal / self-consumption)
 *   bit 5 = exports
 *   bit 6 = summary (batch invoice)
 *   bit 7 = self-billed (buyer-initiated)
 *
 * All default to false (standard invoice with no sub-type flags set).
 * ZatcaComplianceService reads these to build the correct invoice type code
 * sent in the UBL XML to ZATCA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('is_third_party')->default(false)->after('notes')->comment('ZATCA BT-3 bit 3: invoiced on behalf of a third party');
            $table->boolean('is_nominal')->default(false)->after('is_third_party')->comment('ZATCA BT-3 bit 4: nominal / self-consumption');
            $table->boolean('is_export')->default(false)->after('is_nominal')->comment('ZATCA BT-3 bit 5: export invoice');
            $table->boolean('is_summary')->default(false)->after('is_export')->comment('ZATCA BT-3 bit 6: summary invoice');
            $table->boolean('is_self_billed')->default(false)->after('is_summary')->comment('ZATCA BT-3 bit 7: buyer-initiated self-billed invoice');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'is_third_party',
                'is_nominal',
                'is_export',
                'is_summary',
                'is_self_billed',
            ]);
        });
    }
};
