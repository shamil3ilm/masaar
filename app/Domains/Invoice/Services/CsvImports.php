<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Imports\CsvReader;
use App\Domains\Invoice\Imports\ImportColumns;
use App\Domains\Invoice\Imports\ImportRows;
use App\Domains\Invoice\Jobs\CheckCsvImport;
use App\Domains\Invoice\Jobs\RunCsvImport;
use App\Domains\Invoice\Models\CsvImport;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Invoices, customers and products brought in from a CSV file.
 *
 * Two steps, so nothing is saved that nobody saw: the file is checked row by
 * row (ImportRows) and the page shows each row's problems and the first
 * records that would be made; only when the person confirms are the rows
 * that passed saved - invoices as drafts, never issued. A small file is
 * checked while the page waits; a large one, and every save, by a queued
 * job.
 *
 * The file is kept on the private imports disk under a name of our own,
 * never served, and removed after config('imports.keep_days') (prune()).
 */
class CsvImports
{
    public const DISK = 'imports';

    /** Problems kept per import: enough to fix a file by, not a copy of it. */
    public const PROBLEMS = 200;

    public function __construct(
        private readonly CsvReader $reader,
        private readonly ImportRows $rows,
        private readonly TenantResolver $tenant,
    ) {}

    /**
     * The organization's recent imports, newest first.
     *
     * @return Collection<int, CsvImport>
     */
    public function recent(string $organizationId, int $limit = 20): Collection
    {
        return CsvImport::withoutTenantScope(fn (): Collection => CsvImport::query()
            ->where('org_id', $organizationId)
            ->latest()
            ->orderByDesc('id')
            ->limit($limit)
            ->get());
    }

    public function find(string $organizationId, string $importId): ?CsvImport
    {
        return CsvImport::withoutTenantScope(fn (): ?CsvImport => CsvImport::query()->where('org_id', $organizationId)->find($importId));
    }

    /**
     * Keep the file and check it: now for a small one, by a queued job for a
     * large one.
     *
     * @throws DomainException when the file cannot be taken
     */
    public function upload(Organization $organization, string $kind, UploadedFile $file, ?string $branchId): CsvImport
    {
        if (! in_array($kind, ImportColumns::KINDS, true)) {
            throw new DomainException(__('Choose what the file holds.'));
        }

        if (! $file->isValid()) {
            throw new DomainException(__('The file did not upload completely. Try again.'));
        }

        if ($file->getSize() > (int) config('imports.max_kilobytes') * 1024) {
            throw new DomainException(__('The file is over :size MB.', ['size' => (int) config('imports.max_kilobytes') / 1024]));
        }

        if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            throw new DomainException(__('Upload a .csv file.'));
        }

        $path = $organization->id.'/'.Str::lower(Str::random(32)).'.csv';
        Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

        $import = new CsvImport([
            'kind' => $kind,
            'file_name' => $this->safeName($file->getClientOriginalName()),
            'file_size' => (int) $file->getSize(),
        ]);
        $import->forceFill(['org_id' => $organization->id, 'branch_id' => $branchId, 'status' => CsvImport::CHECKING, 'file_path' => $path])->save();

        if ($this->rowCount($path) <= (int) config('imports.check_now_rows')) {
            $this->check($import);
        } else {
            CheckCsvImport::dispatch($import->id);
        }

        return $import->refresh();
    }

    /**
     * Read the file and check every row, saving nothing.
     */
    public function check(CsvImport $import): void
    {
        $this->process($import, save: false);
    }

    /**
     * Save the rows that passed, once: an import already confirmed is not
     * saved again.
     */
    public function confirm(CsvImport $import): bool
    {
        if (! $import->canImport()) {
            return false;
        }

        $claimed = CsvImport::withoutTenantScope(fn (): int => CsvImport::query()
            ->whereKey($import->id)
            ->where('status', CsvImport::CHECKED)
            ->update(['status' => CsvImport::IMPORTING, 'updated_at' => now()]));

        if ($claimed === 0) {
            return false;
        }

        RunCsvImport::dispatch($import->id);

        return true;
    }

    /**
     * Save what passes, by the job a confirmation queued.
     */
    public function run(CsvImport $import): void
    {
        $this->process($import, save: true);
    }

    /**
     * Remove the files kept longer than they need to be.
     *
     * @return int the files removed
     */
    public function prune(): int
    {
        $removed = 0;

        $due = CsvImport::withoutTenantScope(fn (): Collection => CsvImport::query()
            ->whereNotNull('file_path')
            ->where('created_at', '<', now()->subDays((int) config('imports.keep_days')))
            ->whereNotIn('status', [CsvImport::CHECKING, CsvImport::IMPORTING])
            ->get());

        foreach ($due as $import) {
            Storage::disk(self::DISK)->delete((string) $import->file_path);
            $import->forceFill(['file_path' => null, 'file_removed_at' => now()])->save();
            $removed++;
        }

        return $removed;
    }

    private function process(CsvImport $import, bool $save): void
    {
        $organization = Organization::query()->find($import->org_id);

        if ($organization === null || $import->file_path === null) {
            $this->fail($import, __('The file is no longer kept. Upload it again.'));

            return;
        }

        try {
            $read = $this->reader->read(Storage::disk(self::DISK)->path($import->file_path), (int) config('imports.max_rows'));
        } catch (DomainException $e) {
            $this->fail($import, $e->getMessage());

            return;
        }

        try {
            $result = $this->tenant->runAs((string) $organization->id, fn (): array => $this->rows->process($import->kind, $organization, $import->branch_id, $read['rows'], $save));
        } catch (Throwable $e) {
            Log::error('CSV import failed', ['import_id' => $import->id, 'org_id' => $import->org_id, 'error' => $e->getMessage()]);
            $this->fail($import, __('The file could not be processed. The error is logged under :reference.', ['reference' => $import->id]));

            return;
        }

        $import->forceFill([
            'status' => $save ? CsvImport::IMPORTED : CsvImport::CHECKED,
            'rows_total' => count($read['rows']),
            'rows_valid' => $result['valid'],
            'rows_invalid' => $result['invalid'],
            'rows_imported' => $save ? $result['saved'] : 0,
            'problems' => array_slice($result['problems'], 0, self::PROBLEMS),
            'preview' => $result['preview'],
            'failure' => null,
            ...($save ? ['finished_at' => now()] : ['checked_at' => now()]),
        ])->save();
    }

    private function fail(CsvImport $import, string $why): void
    {
        $import->forceFill(['status' => CsvImport::FAILED, 'failure' => mb_substr($why, 0, 500), 'finished_at' => now()])->save();
    }

    /**
     * Roughly how many rows: lines, which a quoted cell over several lines
     * only overcounts.
     */
    private function rowCount(string $path): int
    {
        return max(0, substr_count((string) Storage::disk(self::DISK)->get($path), "\n") - 1);
    }

    /**
     * The name shown, without anything a header or a file system would read
     * as structure.
     */
    private function safeName(string $name): string
    {
        $clean = trim((string) preg_replace('/[^\w.\- ]+/u', '_', basename($name)));

        return Str::limit($clean === '' ? 'import.csv' : $clean, 120, '');
    }
}
