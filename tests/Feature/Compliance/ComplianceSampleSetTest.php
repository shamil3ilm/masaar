<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Compliance\Fatoora\Services\ComplianceSampleSet;
use App\Domains\Compliance\Fatoora\Services\InvoiceHasher;
use Tests\TestCase;

/**
 * The compliance documents form one chain, from ZATCA's initial hash through
 * each document as it is submitted.
 */
class ComplianceSampleSetTest extends TestCase
{
    public function test_six_documents_chain_from_zatcas_hash(): void
    {
        $documents = $this->build();

        $this->assertSame(array_keys(ComplianceSampleSet::DOCUMENTS), array_keys($documents));

        $previous = FatooraConfig::DEFAULT_FIRST_INVOICE_PIH;
        $icv = 0;

        foreach ($documents as $document) {
            $this->assertSame($previous, $document['data']->previousInvoiceHash);
            $this->assertSame(++$icv, $document['data']->icv);

            $previous = $document['hash'];
        }
    }

    /**
     * A caller that signs before submitting has to chain from what it sends.
     */
    public function test_the_chain_follows_the_submitted_bytes(): void
    {
        $documents = $this->build(fn (string $xml): string => str_replace('Test Product', 'Signed Product', $xml));

        $first = $documents['standard_invoice'];

        $this->assertStringContainsString('Signed Product', $first['xml']);
        $this->assertSame(
            app(InvoiceHasher::class)->hash($first['xml']),
            $documents['standard_credit_note']['data']->previousInvoiceHash
        );
    }

    private function build(?\Closure $finalize = null): array
    {
        return app(ComplianceSampleSet::class)->build(
            sellerName: 'Acme Trading',
            sellerVatNumber: '399999999900003',
            sellerCrNumber: '1010101010',
            sellerAddress: new AddressData(
                street: 'King Fahd Road',
                buildingNumber: '1234',
                plotIdentification: '5678',
                district: 'Al Olaya',
                city: 'Riyadh',
                postalCode: '12345',
                countrySubentity: 'Riyadh Region',
            ),
            numberPrefix: 'TEST',
            finalize: $finalize,
        );
    }
}
