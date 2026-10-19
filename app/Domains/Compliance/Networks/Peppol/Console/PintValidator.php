<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Console;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The published PINT rules, run over a document: what a service provider
 * checks before it carries one.
 *
 * The same run as PintConformanceTest: the specification's two compiled
 * schematrons (PINT, and the jurisdiction's aligned rules) through Saxon,
 * billing or self-billing by the document's CustomizationID, and the UBL 2.1
 * schema where its package is unpacked beside them, since the schematrons do
 * not look at element order. Every failed assertion flagged fatal or error
 * comes back as the rule's own text, which starts with its ID
 * ("[ibr-003]-An Invoice MUST have ...").
 *
 * The artefacts are free downloads that are not committed, so they are
 * found through peppol.artefacts_path (PEPPOL_ARTEFACTS_PATH) and the jar
 * through peppol.xslt_jar (PEPPOL_XSLT_JAR); available() says whether both
 * are here.
 *
 * It starts a Java process, so it is operator tooling, as every other
 * country's SDK validator is: tests, commands and the service-provider
 * simulator use it,
 * and the simulator refuses to run in production (NetworkServiceProvider).
 * Domain services on a production path must not (NoShellOutTest).
 *
 * Not final: tests that do not have Java stand in for it.
 */
class PintValidator
{
    private const RULES = ['PINT-UBL-validation-preprocessed.xslt', 'PINT-jurisdiction-aligned-rules.xslt'];

    private const SVRL = 'http://purl.oclc.org/dsdl/svrl';

    /** One schematron run: Saxon's start and compile is most of it. */
    private const TIMEOUT = 120;

    public function available(): bool
    {
        return is_dir($this->artefacts().'/billing') && is_file($this->jar());
    }

    /**
     * Every rule the document breaks; none when it passes.
     *
     * @return list<string>
     *
     * @throws RuntimeException the rules cannot be run here, or Saxon failed
     */
    public function failures(string $xml): array
    {
        if (! $this->available()) {
            throw new RuntimeException('The PINT rules cannot run here: set PEPPOL_ARTEFACTS_PATH and PEPPOL_XSLT_JAR.');
        }

        $document = new DOMDocument;

        // LIBXML_NONET: a document under judgement never makes this server fetch.
        if (! @$document->loadXML($xml, LIBXML_NONET) || $document->documentElement === null) {
            return ['XSD: the document is not well-formed XML.'];
        }

        return [...$this->schema($document), ...$this->schematron($document, $xml)];
    }

    /**
     * @return list<string>
     */
    private function schema(DOMDocument $document): array
    {
        $root = $document->documentElement?->localName === 'CreditNote' ? 'CreditNote' : 'Invoice';
        $xsd = $this->artefacts()."/ubl21/xsd/maindoc/UBL-{$root}-2.1.xsd";

        if (! is_file($xsd)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document->schemaValidate($xsd);

            return array_map(
                fn ($error): string => 'XSD: '.trim($error->message)." (line {$error->line})",
                libxml_get_errors(),
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<string>
     */
    private function schematron(DOMDocument $document, string $xml): array
    {
        $xpath = new DOMXPath($document);
        $customization = (string) $xpath->evaluate('string(/*/*[local-name()="CustomizationID"])');
        $spec = str_contains($customization, 'selfbilling') ? 'selfbilling' : 'billing';
        $transaction = $document->documentElement?->localName === 'CreditNote' ? 'trn-creditnote' : 'trn-invoice';

        $work = sys_get_temp_dir().'/pint-'.bin2hex(random_bytes(6));
        mkdir($work);
        file_put_contents($work.'/document.xml', $xml);
        $failures = [];

        try {
            foreach (self::RULES as $rules) {
                $result = Process::timeout(self::TIMEOUT)->run([
                    'java', '-cp', $this->jar(), 'net.sf.saxon.Transform',
                    '-s:'.$work.'/document.xml',
                    '-xsl:'.$this->artefacts()."/{$spec}/{$transaction}/schematron/{$rules}",
                    '-o:'.$work.'/report.svrl',
                ]);

                if (! $result->successful() || ! is_file($work.'/report.svrl')) {
                    throw new RuntimeException("Saxon could not run {$rules}: ".trim($result->errorOutput()));
                }

                $failures = [...$failures, ...$this->failedAsserts((string) file_get_contents($work.'/report.svrl'))];
                @unlink($work.'/report.svrl');
            }
        } finally {
            array_map('unlink', glob($work.'/*') ?: []);
            @rmdir($work);
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private function failedAsserts(string $svrl): array
    {
        $report = new DOMDocument;

        if (! @$report->loadXML($svrl, LIBXML_NONET)) {
            throw new RuntimeException('Saxon wrote a report that is not XML.');
        }

        $xpath = new DOMXPath($report);
        $xpath->registerNamespace('svrl', self::SVRL);
        $failures = [];

        foreach ($xpath->query('//svrl:failed-assert[@flag="fatal" or @flag="error"]') ?: [] as $assert) {
            $text = (string) preg_replace('/\s+/', ' ', trim($assert->textContent));
            $id = $assert instanceof DOMElement ? $assert->getAttribute('id') : '';

            // The published rules lead with their ID; one that does not is given it.
            $failures[] = $id !== '' && ! str_starts_with($text, '['.$id.']') ? "[{$id}]-{$text}" : $text;
        }

        return $failures;
    }

    private function artefacts(): string
    {
        return rtrim(str_replace('\\', '/', (string) config('peppol.artefacts_path')), '/');
    }

    private function jar(): string
    {
        return (string) config('peppol.xslt_jar');
    }
}
