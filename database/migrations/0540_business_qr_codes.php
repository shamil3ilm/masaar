<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization's own QR codes (BusinessQrCodes): a contact card, payment
 * details, or a link to its location or website. data holds the kind's
 * fields; the QR's content is made from them when it is drawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_qr_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('kind', 16);
            $table->string('label', 80);
            $table->json('data');
            $table->timestamps();

            $table->index('org_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_qr_codes');
    }
};
