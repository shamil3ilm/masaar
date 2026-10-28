<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * An issued invoice is refused change by the database itself, not only by
 * the model.
 *
 * Invoice's updating event froze the document, but only for writes that go
 * through the model: a query-builder update, a console session or a script
 * on the server rewrote a total or a signature without anything noticing.
 * The solution must not allow an issued document to be changed (ZATCA
 * E-Invoicing Detailed Guidelines 5.6, prohibited functions, p27; 5.4 p26),
 * so these triggers refuse it below every code path:
 *
 * - invoices: once the row has left the draft, every column in FROZEN keeps
 *   its value; the stamp (SET_ONCE) goes from empty to set once, and qr_code
 *   is replaced once, in the write that first stores the cleared copy; the
 *   status never goes back to draft; and the row is never deleted. What may
 *   still move is Invoice::MUTABLE_AFTER_FINALIZED, which says why.
 * - invoice_lines, invoice_charges, invoice_prepayments: no row of an issued
 *   invoice is added, changed or removed.
 *
 * The lists are written here rather than read from the model, so the
 * triggers stay what this migration made them. FrozenInvoiceSchemaTest
 * checks that every invoices column is in exactly one list: a column added
 * later needs a migration replacing these triggers.
 *
 * MySQL only. The test suite also runs on SQLite, where the model's guard is
 * the one tested; the triggers are tested on MySQL (FrozenInvoiceDatabaseTest).
 */
return new class extends Migration
{
    /** @var list<string> */
    public const FROZEN = [
        'id', 'org_id', 'profile_id', 'branch_id', 'device_id', 'invoice_number', 'type', 'document_type',
        'issue_date', 'supply_date', 'supply_end_date', 'issued_at', 'issued_by', 'sold_at', 'due_date',
        'currency', 'exchange_rate', 'buyer_name', 'buyer_name_ar', 'buyer_vat_number', 'buyer_id',
        'buyer_id_scheme', 'buyer_address', 'customer_id', 'payment_means_code', 'payment_terms', 'billing_ref',
        'order_reference', 'contract_reference', 'buyer_reference', 'adjustment_reason', 'subtotal',
        'discount_amount', 'tax_amount', 'total', 'prepaid_amount', 'prices_include_vat', 'icv',
        'jurisdiction_data', 'is_third_party', 'is_nominal', 'is_export', 'is_summary', 'is_self_billed',
        'is_government', 'insurance_split_id', 'split_role', 'notes', 'erp_reference_id', 'idempotency_key',
        'idempotency_hash', 'cert_id', 'rule_version', 'schema_version', 'determined_at',
        'signature_algorithm', 'hash_algorithm', 'created_at', 'created_by', 'created_via', 'license_id',
    ];

    /** @var list<string> */
    public const SET_ONCE = ['hash', 'qr_code', 'signed_xml', 'cleared_xml'];

    /** @var list<string> */
    public const MUTABLE = ['status', 'zatca_response', 'updated_at'];

    /** @var list<string> */
    private const PARTS = ['invoice_lines', 'invoice_charges', 'invoice_prepayments'];

    private const REFUSED = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = ";

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $changed = implode("\n            OR ", array_map(fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)", self::FROZEN));
        $restamped = implode("\n            OR ", array_map(
            fn (string $column): string => "(OLD.`{$column}` IS NOT NULL AND NOT (NEW.`{$column}` <=> OLD.`{$column}`)"
                .($column === 'qr_code' ? ' AND NOT (OLD.`cleared_xml` IS NULL AND NEW.`cleared_xml` IS NOT NULL)' : '').')',
            self::SET_ONCE,
        ));

        $this->create(<<<SQL
            CREATE TRIGGER invoices_issued_frozen BEFORE UPDATE ON invoices FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    IF NEW.status = 'draft'
                        OR {$changed}
                    THEN
                        {$this->refuse('An issued invoice cannot be changed. Issue a credit or debit note.')}
                    END IF;
                    IF {$restamped}
                    THEN
                        {$this->refuse('An issued invoice\'s signature, hash, QR and cleared copy are written once.')}
                    END IF;
                END IF;
            END
            SQL);

        $this->create(<<<SQL
            CREATE TRIGGER invoices_issued_kept BEFORE DELETE ON invoices FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    {$this->refuse('An issued invoice cannot be deleted. Issue a credit note.')}
                END IF;
            END
            SQL);

        foreach (self::PARTS as $table) {
            foreach (['INSERT' => ['NEW'], 'UPDATE' => ['OLD', 'NEW'], 'DELETE' => ['OLD']] as $event => $rows) {
                $issued = implode(' OR ', array_map(
                    fn (string $row): string => "EXISTS (SELECT 1 FROM invoices WHERE invoices.id = {$row}.invoice_id AND invoices.status <> 'draft')",
                    $rows,
                ));

                $this->create(<<<SQL
                    CREATE TRIGGER {$this->partTrigger($table, $event)} BEFORE {$event} ON {$table} FOR EACH ROW
                    BEGIN
                        IF {$issued} THEN
                            {$this->refuse('A line, charge or prepayment of an issued invoice cannot be changed.')}
                        END IF;
                    END
                    SQL);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS invoices_issued_frozen');
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_issued_kept');

        foreach (self::PARTS as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$this->partTrigger($table, $event));
            }
        }
    }

    private function partTrigger(string $table, string $event): string
    {
        return $table.'_issued_'.strtolower($event);
    }

    private function refuse(string $message): string
    {
        return self::REFUSED."'".str_replace("'", "''", $message)."';";
    }

    /**
     * Each trigger, with a refusal that says what to change. With binary
     * logging on, MySQL lets an account without SUPER create a trigger only
     * when log_bin_trust_function_creators is set (error 1419), and any
     * account needs the TRIGGER privilege on the database (1142).
     */
    private function create(string $sql): void
    {
        try {
            DB::unprepared($sql);
        } catch (QueryException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);

            if (in_array($code, [1419, 1142, 1227], true)) {
                throw new RuntimeException(
                    'The database refused to create the immutability triggers ('.$e->errorInfo[2].'). '
                    .'Either set log_bin_trust_function_creators=1 on the MySQL server (the triggers are deterministic) '
                    .'and give the migrating user the TRIGGER privilege, or run migrations as a user that has both '
                    .'through DB_MIGRATION_USERNAME and DB_MIGRATION_PASSWORD. See docs/operations/DEPLOYMENT.md.',
                    previous: $e,
                );
            }

            throw $e;
        }
    }
};
