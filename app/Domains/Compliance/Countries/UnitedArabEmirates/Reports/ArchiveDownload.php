<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Reports;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The UAE archive (DocumentArchive) as a download, for the portal and the
 * console alike: the period asked for, the ZIP written to a temporary file
 * and removed once sent, and the export put on the organization's activity
 * record - who took a copy of which documents, and when.
 */
final class ArchiveDownload
{
    public const AUDIT_ACTION = 'uae.archive.exported';

    public function __construct(
        private readonly DocumentArchive $archive,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  int  $maxDays  the longest period one download may cover
     */
    public function respond(Request $request, Organization $organization, int $maxDays): BinaryFileResponse
    {
        $from = CarbonImmutable::parse($request->validate(['from' => ['required', 'date_format:Y-m-d']])['from']);
        $period = $request->validate([
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$from->toDateString(), 'before_or_equal:'.$from->addDays($maxDays - 1)->toDateString()],
        ], [
            'to.before_or_equal' => __('An archive covers at most :days days. Download a longer period in parts.', ['days' => $maxDays]),
        ]);

        $to = CarbonImmutable::parse($period['to']);
        $path = (string) tempnam(sys_get_temp_dir(), 'archive');

        $documents = $this->archive->write($organization, $from, $to, $path);

        $this->audit->logFor((string) $organization->id, self::AUDIT_ACTION, $organization, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'documents' => $documents,
            'sha256' => hash_file('sha256', $path),
        ]);

        return response()
            ->download($path, "{$this->archive->trn($organization)}_{$from->format('Ymd')}-{$to->format('Ymd')}_e-invoices.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
