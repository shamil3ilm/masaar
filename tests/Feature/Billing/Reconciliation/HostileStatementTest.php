<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Reconciliation;

use App\Domains\Billing\Reconciliation\Models\StatementImport;
use App\Domains\Billing\Reconciliation\Models\StatementLine;
use App\Domains\Billing\Reconciliation\Services\StatementImports;
use App\Domains\Billing\Reconciliation\StatementKind;
use App\Domains\Billing\Reconciliation\StatementRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Files that are not what they say, or are out to do harm: external
 * entities and entity bombs in XML, a document type hidden in UTF-16,
 * malformed and foreign XML, files too large or too long, binary files,
 * text in the wrong encoding, impossible dates and sums that do not add
 * up. Each is refused whole, nothing of it kept, and the file never
 * reaches out to anything.
 */
class HostileStatementTest extends TestCase
{
    use ReconcilesStatements;
    use RefreshDatabase;

    private const ENTRY = '<Ntry><Amt Ccy="SAR">1150.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts><BookgDt><Dt>2026-10-03</Dt></BookgDt><AddtlNtryInf>&xxe;</AddtlNtryInf></Ntry>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReconciliation();
    }

    public function test_an_external_entity_is_never_read(): void
    {
        $secret = storage_path('framework/testing-secret-'.uniqid().'.txt');
        file_put_contents($secret, 'TOP-SECRET-VALUE');

        $xml = '<?xml version="1.0"?><!DOCTYPE Document [<!ENTITY xxe SYSTEM "file:///'.str_replace('\\', '/', $secret).'">]>'
            .'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt><Stmt><Id>X</Id>'
            .'<Acct><Id><IBAN>SA0380000000608010167519</IBAN></Id><Ccy>SAR</Ccy></Acct>'.self::ENTRY.'</Stmt></BkToCstmrStmt></Document>';

        try {
            $this->camt($xml);
            $this->fail('A statement declaring an external entity was read.');
        } catch (StatementRejected $e) {
            $this->assertStringContainsString('document type', $e->getMessage());
        } finally {
            @unlink($secret);
        }

        $this->assertNothingKept();
        $this->assertFalse(StatementLine::query()->where('description', 'like', '%TOP-SECRET%')->exists());
    }

    public function test_an_entity_bomb_is_refused(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">'
            .'<!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;"><!ENTITY xxe "&lol3;&lol3;&lol3;&lol3;&lol3;">]>'
            .'<Document><BkToCstmrStmt><Stmt><Id>X</Id>'.self::ENTRY.'</Stmt></BkToCstmrStmt></Document>';

        $this->expectException(StatementRejected::class);

        $this->camt($xml);
    }

    /** UTF-16 hides the declaration from a text search; the parser still sees it. */
    public function test_a_doctype_in_utf16_is_refused(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE Document [<!ENTITY xxe "x">]>'
            .'<Document><BkToCstmrStmt><Stmt><Id>X</Id><Acct><Id><IBAN>SA0380000000608010167519</IBAN></Id></Acct>'.self::ENTRY.'</Stmt></BkToCstmrStmt></Document>';

        $this->expectException(StatementRejected::class);

        $this->camt("\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));
    }

    public function test_malformed_and_foreign_xml_are_refused(): void
    {
        foreach ([
            '<Document><BkToCstmrStmt><Stmt>',
            '<?xml version="1.0"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><ID>INV-1</ID></Invoice>',
            '',
        ] as $xml) {
            try {
                $this->camt($xml);
                $this->fail('Not a statement, and read as one: '.$xml);
            } catch (StatementRejected) {
            }
        }

        $this->assertNothingKept();
    }

    public function test_oversized_statements_are_refused(): void
    {
        config(['billing.reconciliation.max_kilobytes' => 1]);

        try {
            $this->statement([['2026-10-03', '10.00', str_repeat('A', 100), str_repeat('long text ', 150)]]);
            $this->fail('A file over the limit was read.');
        } catch (StatementRejected $e) {
            $this->assertStringContainsString('larger', $e->getMessage());
        }

        config(['billing.reconciliation.max_kilobytes' => 5120, 'billing.reconciliation.max_lines' => 2]);

        try {
            $this->statement([['2026-10-03', '10.00'], ['2026-10-04', '11.00'], ['2026-10-05', '12.00']]);
            $this->fail('A statement with more lines than allowed was read.');
        } catch (StatementRejected $e) {
            $this->assertStringContainsString('more than 2 lines', $e->getMessage());
        }

        $this->assertNothingKept();
    }

    public function test_an_oversized_upload_is_refused(): void
    {
        config(['billing.reconciliation.max_kilobytes' => 1]);

        $this->actingAs($this->operations)->post(route('admin.finance.reconciliation.imports.store'), [
            'kind' => 'mt940', 'bank_account' => $this->account, 'file' => UploadedFile::fake()->create('statement.txt', 2048),
        ])->assertSessionHasErrors('file');

        $this->assertNothingKept();
    }

    public function test_binary_and_misencoded_files_are_refused(): void
    {
        $import = app(StatementImports::class);

        foreach ([
            "\x89PNG\r\n\x1A\n\0\0\0\rIHDR",
            "PK\x03\x04\x14\0\x06\0",
            "Date,Amount,Reference,Description,Payer\n2026-10-03,10.00,\xFF\xFE\xFA,x,y\n",
        ] as $bytes) {
            try {
                $import->import(StatementKind::Csv, 'statement.csv', $bytes, null, $this->profile, null);
                $this->fail('An unreadable file was read.');
            } catch (StatementRejected) {
            }
        }

        $this->assertNothingKept();
    }

    public function test_a_utf16_spreadsheet_export_is_read(): void
    {
        $csv = "Date,Amount,Reference,Description,Payer\n2026-10-03,1150.00,PLT-9,تحويل,Acme Trading\n";

        $import = app(StatementImports::class)->import(StatementKind::Csv, 'export.csv', "\xFF\xFE".mb_convert_encoding($csv, 'UTF-16LE', 'UTF-8'), null, $this->profile, null);

        $this->assertSame('تحويل', $import->lines()->sole()->description);
    }

    public function test_impossible_values_are_refused(): void
    {
        $import = app(StatementImports::class);
        $mt940 = (string) file_get_contents(base_path('tests/Fixtures/statements/mt940.txt'));

        $cases = [
            // 31 February, and an amount that is not one.
            fn () => $this->statement([['2026-02-31', '10.00']]),
            fn () => $this->statement([['2026-10-03', '1O.00']]),
            // A statement line that is not in the layout.
            fn () => $import->import(StatementKind::Mt940, 'bad.txt', str_replace(':61:2610041004C575,00NTRF', ':61:XYZ', $mt940), $this->account, null, null),
            // A settlement row whose gross less fee is not its net.
            fn () => $import->import(StatementKind::Settlement, 'bad.csv', "settlement_id,settled_on,transaction_id,reference,currency,gross,fee,net\nS1,2026-10-06,txn_a,PLT-1,SAR,100.00,2.50,99.00\n", null, null, null),
        ];
        config(['billing.gateway' => 'simulator']);

        foreach ($cases as $index => $case) {
            try {
                $case();
                $this->fail('Case '.$index.' was read.');
            } catch (StatementRejected) {
            }
        }

        $this->assertNothingKept();
    }

    private function camt(string $xml): StatementImport
    {
        return app(StatementImports::class)->import(StatementKind::Camt053, 'statement.xml', $xml, $this->account, null, null);
    }

    private function assertNothingKept(): void
    {
        $this->assertSame(0, StatementImport::query()->count());
        $this->assertSame(0, StatementLine::query()->count());
        $this->assertSame([], Storage::disk(StatementImports::DISK)->allFiles());
    }
}
