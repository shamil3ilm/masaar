<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A link that opens one issued invoice without signing in (InvoiceLinks),
 * for its QR code. Found by the SHA-256 of its token; the token itself is
 * kept encrypted so its QR can be drawn again. Revoked, it opens nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('invoice_id');
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->uuid('created_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamps();

            $table->index(['org_id', 'invoice_id']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_links');
    }
};
