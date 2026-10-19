<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Documents sent to a report-only endpoint are not exchanged.
 *
 * A country's reserved buyer endpoints (peppol.countries.*.report_only_endpoints)
 * are reported to the authority and never delivered (UAE Electronic
 * Invoicing Guidelines v1.1, p23, p26, p28). Such a document used to sit in
 * 'sent', waiting for a delivery that never comes and asked about for a
 * fortnight; it is now 'not_exchanged', done once reported. No column
 * changes, only the status of those already sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $endpoints = $this->endpoints();

        if ($endpoints === []) {
            return;
        }

        DB::table('peppol_documents')
            ->where('direction', 'out')
            ->where('status', 'sent')
            ->whereIn('receiver_endpoint', $endpoints)
            ->update(['status' => 'not_exchanged']);
    }

    public function down(): void
    {
        DB::table('peppol_documents')
            ->where('direction', 'out')
            ->where('status', 'not_exchanged')
            ->update(['status' => 'sent']);
    }

    /**
     * @return list<string>
     */
    private function endpoints(): array
    {
        $endpoints = [];

        foreach ((array) config('peppol.countries', []) as $country) {
            foreach ((array) ($country['report_only_endpoints'] ?? []) as $endpoint) {
                $endpoints[] = (string) $endpoint;
            }
        }

        return $endpoints;
    }
};
