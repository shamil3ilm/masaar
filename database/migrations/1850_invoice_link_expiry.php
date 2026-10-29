<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an invoice link stops opening (InvoiceLinks), and how long an
 * organization's new links last unless the person making one chooses
 * otherwise. Null in both is never, which is what every link was before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_links', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedSmallInteger('invoice_link_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('invoice_link_days');
        });

        Schema::table('invoice_links', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
