<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A walk-in customer has no name, and the authority does not ask for one.
 *
 * ZATCA's data dictionary marks the buyer name (BT-44) Conditional on a
 * simplified invoice, and BR-KSA-25 states the condition: it is mandatory only
 * when the exemption reason is VATEX-SA-EDU or VATEX-SA-HEA - private
 * education or healthcare supplied to a Saudi citizen. The authority's own
 * sample simplified invoice in its SDK carries an empty
 * cac:AccountingCustomerParty: no name, no address, no identifier.
 *
 * This column was NOT NULL, the request required the field, the portal form
 * marked it required, and the validator refused an invoice without it. So a
 * cashier ringing up a cash sale had to type something into the buyer's name,
 * and whatever they typed was filed to the authority as the buyer's
 * registered name - invented data in a legal tax document, there only because
 * the platform would not accept that there was no name to give.
 *
 * Nullable now. Existing rows keep whatever they hold: an issued invoice's
 * signed XML is what the authority has, and rewriting the row underneath it
 * would make the two disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('buyer_name', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('buyer_name', 255)->nullable(false)->change();
        });
    }
};
