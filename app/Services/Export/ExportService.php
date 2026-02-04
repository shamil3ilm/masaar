<?php

declare(strict_types=1);

namespace App\Services\Export;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportService
{
    /**
     * Export data to CSV.
     */
    public function toCsv(Collection $data, array $columns, string $filename): string
    {
        $filePath = "exports/{$filename}.csv";

        $handle = fopen('php://temp', 'r+');

        // Write header
        fputcsv($handle, array_values($columns));

        // Write data rows
        foreach ($data as $row) {
            $rowData = [];
            foreach (array_keys($columns) as $key) {
                $rowData[] = data_get($row, $key, '');
            }
            fputcsv($handle, $rowData);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        Storage::put($filePath, $content);

        return Storage::path($filePath);
    }

    /**
     * Export data to Excel (CSV with UTF-8 BOM for Excel compatibility).
     */
    public function toExcel(Collection $data, array $columns, string $filename): string
    {
        $filePath = "exports/{$filename}.xlsx";

        // For proper Excel export, you'd use PhpSpreadsheet
        // This is a simplified CSV export with BOM for Excel compatibility
        $handle = fopen('php://temp', 'r+');

        // UTF-8 BOM for Excel
        fwrite($handle, "\xEF\xBB\xBF");

        // Write header
        fputcsv($handle, array_values($columns));

        // Write data rows
        foreach ($data as $row) {
            $rowData = [];
            foreach (array_keys($columns) as $key) {
                $value = data_get($row, $key, '');
                // Format numbers for Excel
                if (is_numeric($value)) {
                    $value = (string) $value;
                }
                $rowData[] = $value;
            }
            fputcsv($handle, $rowData);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        Storage::put($filePath, $content);

        return Storage::path($filePath);
    }

    /**
     * Export data to PDF.
     */
    public function toPdf(string $view, array $data, string $filename, array $options = []): string
    {
        $filePath = "exports/{$filename}.pdf";

        $pdf = Pdf::loadView($view, $data);

        // Set paper size
        $paper = $options['paper'] ?? 'a4';
        $orientation = $options['orientation'] ?? 'portrait';
        $pdf->setPaper($paper, $orientation);

        // Set options
        if (isset($options['dpi'])) {
            $pdf->setOption('dpi', $options['dpi']);
        }

        Storage::put($filePath, $pdf->output());

        return Storage::path($filePath);
    }

    /**
     * Generate a downloadable response.
     */
    public function download(string $filePath, ?string $name = null): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $name = $name ?? basename($filePath);

        return response()->download($filePath, $name)->deleteFileAfterSend(true);
    }

    /**
     * Clean up old export files.
     */
    public function cleanupOldExports(int $olderThanMinutes = 60): int
    {
        $files = Storage::files('exports');
        $deleted = 0;

        foreach ($files as $file) {
            $lastModified = Storage::lastModified($file);
            $threshold = now()->subMinutes($olderThanMinutes)->timestamp;

            if ($lastModified < $threshold) {
                Storage::delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
