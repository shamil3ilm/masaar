<?php

declare(strict_types=1);

use App\Domains\Invoice\Support\UnitCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database was filling in a unit code that does not exist.
 *
 * invoice_lines.unit_code defaulted to 'PCE'. ZATCA's data dictionary says
 * units "are recommended to be from UN/ECE Recommendation 20, Revision 16
 * (2020)" and Peppol BIS Billing 3.0 requires that list; PCE is in neither.
 * Recommendation 20's piece is H87.
 *
 * This default is why correcting the application was not enough. The column
 * is NOT NULL, so every line inserted without a unit - which is most of them,
 * since neither the pipeline nor the compliance API asks for one - took its
 * value here, underneath the six places in app/ that also said 'PCE'. A
 * default in the schema outranks every default above it.
 *
 * ZATCA never objected because its schematron does not check the code against
 * any list: the only EN16931 rule on unitCode is that the attribute is at
 * most 127 characters. So this passed for as long as it existed and would
 * have failed the first time a document went down the UAE's Peppol path.
 *
 * Draft lines are corrected; issued ones are not. An issued invoice's signed
 * XML is what the authority holds and what the hash chain covers, so changing
 * the row underneath would make the stored line disagree with the document
 * that was actually filed - a worse problem than an unfashionable code, and
 * an undetectable one.
 */
return new class extends Migration
{
    private const WAS = 'PCE';

    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->string('unit_code', 10)->default(UnitCode::DEFAULT)->change();
        });

        $this->correctDrafts(self::WAS, UnitCode::DEFAULT);
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->string('unit_code', 10)->default(self::WAS)->change();
        });

        $this->correctDrafts(UnitCode::DEFAULT, self::WAS);
    }

    /**
     * Only lines whose invoice is still a draft.
     *
     * Scoped with whereIn on a subquery rather than a join, because MySQL and
     * SQLite disagree about updating a joined table and this has to run on
     * both.
     */
    private function correctDrafts(string $from, string $to): void
    {
        DB::table('invoice_lines')
            ->where('unit_code', $from)
            ->whereIn('invoice_id', DB::table('invoices')->select('id')->where('status', 'draft'))
            ->update(['unit_code' => $to]);
    }
};
