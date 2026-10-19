<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\UaeDocuments;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaePortal;
use App\Domains\Compliance\Networks\Peppol\Receiving\ReceivedReader;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Invoices other suppliers sent a UAE organization over Peppol.
 *
 * Each is listed and shown by the few fields ReceivedReader takes from its
 * XML - text, escaped like anything else on the page. The XML itself is only
 * ever downloaded, never rendered: it is another party's document, and the
 * record the taxpayer keeps.
 *
 * Only the session's organization's documents are found; another's is a
 * 404, as is every page here for an organization outside the UAE or on a
 * deployment without UAE e-invoicing.
 */
class PortalReceivedController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly UaePortal $portal,
        private readonly UaeDocuments $documents,
        private readonly ReceivedReader $reader,
    ) {}

    public function index(): View
    {
        $organization = $this->portal->organization();
        $documents = $this->documents->received((string) $organization->id, self::PER_PAGE);

        return view('countries.united-arab-emirates.received.index', [
            'organization' => $organization,
            'documents' => $documents,
            'summaries' => $documents->getCollection()
                ->mapWithKeys(fn ($document): array => [$document->id => $this->reader->summarise($document->xml)]),
        ]);
    }

    public function show(string $documentId): View
    {
        $organization = $this->portal->organization();
        $document = $this->documents->findReceived((string) $organization->id, $documentId);

        return view('countries.united-arab-emirates.received.show', [
            'organization' => $organization,
            'document' => $document,
            'summary' => $this->reader->summarise($document->xml),
        ]);
    }

    public function xml(string $documentId): Response
    {
        $organization = $this->portal->organization();
        $document = $this->documents->findReceived((string) $organization->id, $documentId);

        return response((string) $document->xml, 200, UaeDocuments::xmlHeaders($document));
    }
}
