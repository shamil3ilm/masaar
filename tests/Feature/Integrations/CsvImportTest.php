<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Jobs\CheckCsvImport;
use App\Domains\Invoice\Models\CsvImport;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\Product;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Portal › Import: a template per kind with the organization's country's
 * columns; the file checked row by row, its problems shown at the row and
 * column they are about; nothing saved until confirmed, and invoices only
 * ever drafted. Large files are checked by a queued job, and the file is
 * kept privately and removed after its keeping period.
 */
class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('imports');
        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي للتجارة', 'country' => 'SA', 'vat_number' => '399999999900003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345', 'status' => 'active',
        ]);
        $this->member = $this->memberAs('member');
    }

    public function test_templates_follow_the_country(): void
    {
        $saudi = $this->template('invoices');
        $this->assertContains('buyer_address.building_number', $saudi[0]);
        $this->assertContains('line.description_ar', $saudi[0]);
        $this->assertNotContains('customer_id', $saudi[0]);
        $this->assertNotContains('charges', $saudi[0]);
        $this->assertSame('INV-1001', $saudi[1][array_search('invoice_number', $saudi[0], true)]);

        $this->assertContains('buyer_id', $this->template('customers')[0]);
        $this->assertContains('unit_code', $this->template('products')[0]);

        $uae = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE', 'status' => 'active']);
        $this->member->organizations()->attach($uae->id, ['role' => 'member', 'status' => 'active']);
        $emirati = $this->template('invoices', $uae);
        $this->assertContains('buyer_subdivision', $emirati[0]);
        $this->assertContains('line.item_name', $emirati[0]);
        $this->assertNotContains('buyer_address.district', $emirati[0]);
    }

    public function test_each_template_imports_as_it_is(): void
    {
        foreach (['invoices', 'customers', 'products'] as $kind) {
            $csv = $this->asMember()->get(route('portal.imports.template', $kind))->assertOk()->getContent();

            $import = $this->upload($kind, (string) $csv);

            $this->assertSame([CsvImport::CHECKED, 1, 0], [$import->status, $import->rows_valid, $import->rows_invalid], $kind.': '.json_encode($import->problems));
        }
    }

    public function test_rows_are_checked_one_by_one(): void
    {
        $import = $this->upload('invoices', $this->csv(
            ['invoice_number', 'type', 'issue_date', 'line.description', 'line.description_ar', 'line.quantity', 'line.unit_price'],
            [
                ['CSV-1', 'simplified', now()->toDateString(), 'Coffee', 'قهوة', '2', '10.00'],
                ['CSV-1', '', '', 'Tea', 'شاي', '1', ''],
                ['CSV-2', 'simplified', now()->toDateString(), 'Water', 'ماء', '3', '1.50'],
                ['CSV-3', 'receipt', now()->toDateString(), 'Juice', 'عصير', '1', '5.00'],
            ],
        ));

        $this->assertSame([CsvImport::CHECKED, 4, 1, 3], [$import->status, $import->rows_total, $import->rows_valid, $import->rows_invalid]);
        $problems = collect($import->problems);
        $this->assertTrue($problems->contains(fn (array $problem): bool => $problem['row'] === 3 && $problem['field'] === 'line.unit_price'));
        $this->assertTrue($problems->contains(fn (array $problem): bool => $problem['row'] === 5 && $problem['field'] === 'type'));
        $this->assertSame('CSV-2', $import->preview[0]['invoice_number']);
        $this->assertCount(0, $this->drafts(), 'checking saves nothing');

        $this->asMember()->get(route('portal.imports.show', $import->id))->assertOk()
            ->assertSee('line.unit_price')
            ->assertSee('Save the 1 rows that passed');
    }

    public function test_confirming_saves_drafts_only(): void
    {
        $import = $this->upload('invoices', $this->csv(
            ['invoice_number', 'type', 'issue_date', 'line.description', 'line.description_ar', 'line.quantity', 'line.unit_price'],
            [
                ['CSV-10', 'simplified', now()->toDateString(), 'Coffee', 'قهوة', '2', '10.00'],
                ['CSV-10', '', '', 'Tea', 'شاي', '1', '4.00'],
                ['CSV-11', 'simplified', now()->toDateString(), 'Water', 'ماء', '3', '1.50'],
            ],
        ));

        $this->asMember()->post(route('portal.imports.confirm', $import->id))->assertSessionHas('success');

        $import->refresh();
        $this->assertSame([CsvImport::IMPORTED, 2], [$import->status, $import->rows_imported]);

        $drafts = $this->drafts();
        $this->assertSame(['CSV-10', 'CSV-11'], $drafts->pluck('invoice_number')->all());
        $this->assertCount(2, $drafts[0]->lines);

        foreach ($drafts as $draft) {
            $this->assertSame(InvoiceStatus::Draft, $draft->status);
            $this->assertNull($draft->issued_at);
            $this->assertSame(['import', $this->member->id], [$draft->created_via, $draft->created_by]);
        }

        $this->asMember()->post(route('portal.imports.confirm', $import->id))->assertSessionHas('error');
        $this->assertCount(2, $this->drafts(), 'a second confirmation saves nothing more');
    }

    public function test_customers_and_products_import(): void
    {
        Customer::create(['org_id' => $this->acme->id, 'name' => 'Existing', 'vat_number' => '310122393500003', 'country_code' => 'SA']);

        $customers = $this->upload('customers', $this->csv(['name', 'name_ar', 'vat_number', 'city', 'country_code'], [
            ['Beta Industries', 'شركة بيتا', '399999999800003', 'Riyadh', 'SA'],
            ['Copy', 'نسخة', '310122393500003', 'Jeddah', 'SA'],
        ]));
        $this->assertSame([1, 1], [$customers->rows_valid, $customers->rows_invalid]);
        $this->assertSame('vat_number', $customers->problems[0]['field']);

        $products = $this->upload('products', $this->csv(['name', 'name_ar', 'sku', 'unit_code', 'unit_price', 'tax_rate'], [
            ['Consulting hour', 'ساعة استشارة', 'CONS-1', 'HUR', '250', '15'],
        ]));

        $this->asMember()->post(route('portal.imports.confirm', $customers->id))->assertSessionHas('success');
        $this->asMember()->post(route('portal.imports.confirm', $products->id))->assertSessionHas('success');

        $customer = Customer::acrossTenants()->where('vat_number', '399999999800003')->sole();
        $product = Product::acrossTenants()->where('sku', 'CONS-1')->sole();
        $this->assertSame(['import', 'import'], [$customer->created_via, $product->created_via]);
    }

    public function test_large_files_are_checked_queued(): void
    {
        config(['imports.check_now_rows' => 1]);
        Queue::fake();

        $import = $this->upload('products', $this->csv(['name', 'name_ar', 'unit_code', 'unit_price', 'tax_rate'], [
            ['One', 'واحد', 'C62', '1', '15'], ['Two', 'اثنان', 'C62', '2', '15'], ['Three', 'ثلاثة', 'C62', '3', '15'],
        ]));

        $this->assertSame(CsvImport::CHECKING, $import->status);
        $this->asMember()->get(route('portal.imports.show', $import->id))->assertSee('Look again');
        Queue::assertPushed(CheckCsvImport::class, fn (CheckCsvImport $job): bool => $job->importId === $import->id);

        app()->call([new CheckCsvImport($import->id), 'handle']);

        $this->assertSame([CsvImport::CHECKED, 3], [$import->fresh()->status, $import->fresh()->rows_valid]);
    }

    public function test_a_bad_file_is_refused(): void
    {
        $latin = $this->upload('products', "name,unit_code\n\xE9t\xE9,C62\n");
        $this->assertSame(CsvImport::FAILED, $latin->status);
        $this->assertStringContainsString('UTF-8', (string) $latin->failure);

        $unknown = $this->upload('products', $this->csv(['name', 'colour', 'unit_code', 'unit_price', 'tax_rate'], [['Pen', 'blue', 'C62', '1', '15']]));
        $this->assertSame('colour', $unknown->problems[0]['field']);

        config(['imports.max_rows' => 2]);
        $many = $this->upload('products', $this->csv(['name'], [['a'], ['b'], ['c']]));
        $this->assertSame(CsvImport::FAILED, $many->status);

        $this->asMember()->post(route('portal.imports.store'), ['kind' => 'products', 'file' => UploadedFile::fake()->createWithContent('products.xlsx', 'PK')])
            ->assertSessionHas('error');
    }

    public function test_files_are_kept_privately_then_removed(): void
    {
        $import = $this->upload('products', $this->csv(['name', 'name_ar', 'unit_code', 'unit_price', 'tax_rate'], [['Pen', 'قلم', 'C62', '1', '15']]));

        Storage::disk('imports')->assertExists($import->file_path);
        $this->assertStringStartsWith($this->acme->id.'/', $import->file_path);
        $this->assertStringNotContainsString('products', $import->file_path, 'stored under a name of our own');

        $this->travel((int) config('imports.keep_days') + 1)->days();
        Artisan::call('imports:prune');

        Storage::disk('imports')->assertMissing($import->file_path);
        $this->assertNull($import->fresh()->file_path);
        $this->assertNotNull($import->fresh()->file_removed_at);
    }

    public function test_viewers_cannot_import(): void
    {
        $viewer = $this->memberAs('viewer');

        $this->actingAs($viewer)->withSession(['portal_org_id' => $this->acme->id])
            ->post(route('portal.imports.store'), ['kind' => 'products', 'file' => UploadedFile::fake()->createWithContent('p.csv', "name\nPen\n")])
            ->assertForbidden();
    }

    public function test_another_organizations_import_is_unfindable(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA', 'status' => 'active']);
        $theirs = (new CsvImport(['kind' => 'products', 'file_name' => 'theirs.csv', 'file_size' => 10]));
        $theirs->forceFill(['org_id' => $rival->id, 'status' => CsvImport::CHECKED, 'file_path' => $rival->id.'/x.csv', 'rows_valid' => 1])->save();

        $this->asMember()->get(route('portal.imports.show', $theirs->id))->assertNotFound();
        $this->asMember()->post(route('portal.imports.confirm', $theirs->id))->assertNotFound();
    }

    private function upload(string $kind, string $csv): CsvImport
    {
        $this->asMember()->post(route('portal.imports.store'), ['kind' => $kind, 'file' => UploadedFile::fake()->createWithContent($kind.'.csv', $csv)])
            ->assertRedirect();

        return CsvImport::acrossTenants()->latest()->orderByDesc('id')->firstOrFail();
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    private function csv(array $header, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');

        foreach ([$header, ...$rows] as $row) {
            fputcsv($stream, $row, escape: '');
        }

        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * @return list<list<string>> the template's rows
     */
    private function template(string $kind, ?Organization $organization = null): array
    {
        $response = $this->actingAs($this->member)->withSession(['portal_org_id' => ($organization ?? $this->acme)->id])
            ->get(route('portal.imports.template', $kind))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $text = (string) $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $text);

        return array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", substr($text, 3)))));
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function drafts(): Collection
    {
        return Invoice::acrossTenants()->with('lines')->where('org_id', $this->acme->id)->orderBy('invoice_number')->get();
    }

    private function asMember(): self
    {
        return $this->actingAs($this->member)->withSession(['portal_org_id' => $this->acme->id]);
    }

    private function memberAs(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
