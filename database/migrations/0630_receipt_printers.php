<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A branch's receipt printer, for the receipt image and ESC/POS output
 * (ReceiptPrinting): its resolution and the dots across its head. Null for
 * the common printer - 203 dpi, 576 dots on an 80 mm roll, 384 on 58 mm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedSmallInteger('receipt_dpi')->nullable();
            $table->unsignedSmallInteger('receipt_dots')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['receipt_dpi', 'receipt_dots']);
        });
    }
};
