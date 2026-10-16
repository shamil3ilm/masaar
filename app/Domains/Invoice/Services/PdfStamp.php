<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Fatoora\Exceptions\SigningException;
use App\Domains\Compliance\Fatoora\Services\CertificateChain;
use Illuminate\Support\Carbon;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfBoolean;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObjectReference;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNull;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfToken;
use setasign\Fpdi\PdfParser\Type\PdfType;

/**
 * The cryptographic stamp on an invoice PDF: a PAdES B-B signature.
 *
 * ZATCA's Security Features standard, 2.2.1 items 10, 11 and 15: a PDF/A-3
 * invoice is stamped with an enveloped PAdES signature at level B-B, and
 * 2.3.4 fixes its shape - /SubFilter /ETSI.CAdES.detached, a /ByteRange over
 * the whole file but /Contents, the claimed time in /M.
 *
 * Appended as an incremental update, so the renderer's bytes - the page, the
 * embedded XML, the PDF/A metadata - are signed exactly as written. The
 * renderer cannot sign, and rewriting its file would mean re-checking every
 * object it emits for PDF/A conformance.
 *
 * The field is invisible: the page already carries the QR a person scans, and
 * the stamp is for software to check.
 */
class PdfStamp
{
    /**
     * Room for the CMS. A device certificate and its signature come to about
     * 1.5 KB; the rest is for the chain the standard asks to be included.
     */
    private const SIGNATURE_BYTES = 8192;

    /** Ten digits for each offset, so the real numbers fit where these sit. */
    private const BYTE_RANGE_PLACEHOLDER = '[0 0000000000 0000000000 0000000000]';

    public function __construct(
        private readonly CadesSignature $cades,
        private readonly CertificateChain $chain,
    ) {}

    /**
     * @throws SigningException
     */
    public function sign(string $pdf, string $privateKeyPem, string $certificatePem): string
    {
        $parser = new PdfParser(StreamReader::createByString($pdf));
        $trailer = $parser->getCrossReference()->getTrailer();
        $root = PdfIndirectObjectReference::ensure(PdfDictionary::get($trailer, 'Root'));
        $catalog = PdfDictionary::ensure(PdfType::resolve($root, $parser));
        $page = $this->firstPage($catalog, $parser);

        $size = (int) PdfNumeric::ensure(PdfDictionary::get($trailer, 'Size'))->value;
        [$signature, $widget, $appearance] = [$size, $size + 1, $size + 2];

        $objects = [
            $root->value => $this->catalog($catalog, $widget, $parser),
            $page->value => $this->page($page, $widget, $parser),
            $signature => '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached'
                .' /ByteRange '.self::BYTE_RANGE_PLACEHOLDER
                .' /Contents <'.str_repeat('0', self::SIGNATURE_BYTES * 2).'>'
                .' /M '.$this->date(Carbon::now()).' >>',
            // Print and Locked (4 + 128): PDF/A requires an annotation be
            // printable, and nothing should move the stamp once it is made.
            $widget => "<< /Type /Annot /Subtype /Widget /FT /Sig /T (ZATCA Stamp) /V {$signature} 0 R"
                ." /F 132 /Rect [0 0 0 0] /P {$page->value} {$page->generationNumber} R /AP << /N {$appearance} 0 R >> >>",
            // An empty appearance: PDF/A wants one on every widget, visible or not.
            $appearance => "<< /Type /XObject /Subtype /Form /BBox [0 0 0 0] /Length 0 >>\nstream\n\nendstream",
        ];

        $generations = [$root->value => $root->generationNumber, $page->value => $page->generationNumber];
        $signed = $this->append($pdf, $objects, $generations, $trailer, $size + 3);

        return $this->fillSignature($signed, strlen($pdf), $privateKeyPem, $certificatePem);
    }

    /**
     * The original file, then the update: objects, xref section, trailer.
     *
     * @param  array<int, string>  $objects
     * @param  array<int, int>  $generations
     */
    private function append(string $pdf, array $objects, array $generations, PdfDictionary $trailer, int $size): string
    {
        $out = str_ends_with($pdf, "\n") ? $pdf : $pdf."\n";
        $offsets = [];

        ksort($objects);

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($out);
            $out .= $number.' '.($generations[$number] ?? 0)." obj\n".$body."\nendobj\n";
        }

        $xref = strlen($out);
        // Led by the free-list head, as Acrobat writes an update: readers
        // that expect a section to start at object 0 otherwise renumber it.
        $out .= "xref\n0 1\n0000000000 65535 f\r\n";

        foreach ($this->runs(array_keys($offsets)) as $run) {
            $out .= $run[0].' '.count($run)."\n";

            foreach ($run as $number) {
                $out .= sprintf("%010d %05d n\r\n", $offsets[$number], $generations[$number] ?? 0);
            }
        }

        // The previous trailer carries on - /Root, /Info and the /ID PDF/A
        // requires - with this update's size and a link back to the last.
        $entries = array_diff_key($trailer->value, array_flip(['Size', 'Prev', 'XRefStm']));
        $out .= "trailer\n<< /Size {$size} /Prev {$this->startXref($pdf)}";

        foreach ($entries as $name => $value) {
            $out .= ' /'.$name.' '.$this->write($value);
        }

        return $out." >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * Sign everything but the /Contents hex string, and write the CMS into it.
     *
     * @throws SigningException
     */
    private function fillSignature(string $pdf, int $from, string $privateKeyPem, string $certificatePem): string
    {
        $range = strpos($pdf, '/ByteRange '.self::BYTE_RANGE_PLACEHOLDER, $from) + strlen('/ByteRange ');
        $start = strpos($pdf, '/Contents <', $range) + strlen('/Contents ');
        $end = $start + self::SIGNATURE_BYTES * 2 + 2;

        $byteRange = str_pad(sprintf('[0 %d %d %d]', $start, $end, strlen($pdf) - $end), strlen(self::BYTE_RANGE_PLACEHOLDER));
        $pdf = substr_replace($pdf, $byteRange, $range, strlen($byteRange));

        $cms = bin2hex($this->cades->sign(
            substr($pdf, 0, $start).substr($pdf, $end),
            $privateKeyPem,
            $certificatePem,
            $this->chain->above($certificatePem),
        ));

        if (strlen($cms) > self::SIGNATURE_BYTES * 2) {
            throw new SigningException('The signature does not fit the space reserved for it in the PDF.');
        }

        return substr_replace($pdf, $cms, $start + 1, strlen($cms));
    }

    /**
     * The catalog, with the signature field in its AcroForm.
     */
    private function catalog(PdfDictionary $catalog, int $widget, PdfParser $parser): string
    {
        $form = isset($catalog->value['AcroForm'])
            ? PdfDictionary::ensure(PdfType::resolve($catalog->value['AcroForm'], $parser))->value
            : [];
        $fields = isset($form['Fields']) ? PdfArray::ensure(PdfType::resolve($form['Fields'], $parser))->value : [];

        $acroForm = array_merge($form, [
            'Fields' => PdfArray::create([...$fields, PdfIndirectObjectReference::create($widget, 0)]),
            // SignaturesExist | AppendOnly: a viewer saving changes must
            // append them, or it breaks the signature it just showed as valid.
            'SigFlags' => PdfNumeric::create(3),
        ]);

        return $this->write(PdfDictionary::create(array_merge($catalog->value, ['AcroForm' => PdfDictionary::create($acroForm)])));
    }

    /**
     * The first page, with the widget among its annotations.
     */
    private function page(PdfIndirectObjectReference $page, int $widget, PdfParser $parser): string
    {
        $dictionary = PdfDictionary::ensure(PdfType::resolve($page, $parser));
        $annots = isset($dictionary->value['Annots'])
            ? PdfArray::ensure(PdfType::resolve($dictionary->value['Annots'], $parser))->value
            : [];

        return $this->write(PdfDictionary::create(array_merge($dictionary->value, [
            'Annots' => PdfArray::create([...$annots, PdfIndirectObjectReference::create($widget, 0)]),
        ])));
    }

    /**
     * @throws SigningException
     */
    private function firstPage(PdfDictionary $catalog, PdfParser $parser): PdfIndirectObjectReference
    {
        $node = PdfDictionary::get($catalog, 'Pages');

        // Bounded, so a malformed tree that points back at itself ends.
        for ($depth = 0; $depth < 32; $depth++) {
            $dictionary = PdfDictionary::ensure(PdfType::resolve($node, $parser));

            if (PdfName::ensure(PdfDictionary::get($dictionary, 'Type'))->value === 'Page') {
                return PdfIndirectObjectReference::ensure($node);
            }

            $node = PdfArray::ensure(PdfType::resolve(PdfDictionary::get($dictionary, 'Kids'), $parser))->value[0]
                ?? throw new SigningException('The PDF has no pages to sign.');
        }

        throw new SigningException('The PDF page tree is too deep to find its first page.');
    }

    /**
     * Group object numbers into runs of consecutive ones: an xref section is
     * written as subsections, each a first number and a count.
     *
     * @param  list<int>  $numbers  Ascending
     * @return list<list<int>>
     */
    private function runs(array $numbers): array
    {
        $runs = [];

        foreach ($numbers as $number) {
            $last = array_key_last($runs);

            if ($last !== null && end($runs[$last]) === $number - 1) {
                $runs[$last][] = $number;
            } else {
                $runs[] = [$number];
            }
        }

        return $runs;
    }

    /**
     * @throws SigningException
     */
    private function startXref(string $pdf): int
    {
        if (preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', substr($pdf, -1024), $match) !== 1) {
            throw new SigningException('The PDF has no cross-reference table to append to.');
        }

        return (int) $match[1];
    }

    /**
     * The claimed signing time, as ISO 32000-1 7.9.4 writes a date.
     */
    private function date(Carbon $time): string
    {
        return '(D:'.$time->copy()->utc()->format('YmdHis')."+00'00')";
    }

    /**
     * A parsed value written back as PDF syntax. Strings and names are kept
     * in the escaped form they were read in, so they come back byte for byte.
     */
    private function write(PdfType $value): string
    {
        return match (true) {
            $value instanceof PdfDictionary => '<<'.implode('', array_map(
                fn (int|string $name, PdfType $entry): string => ' /'.$name.' '.$this->write($entry),
                array_keys($value->value),
                $value->value,
            )).' >>',
            $value instanceof PdfArray => '['.implode(' ', array_map($this->write(...), $value->value)).']',
            $value instanceof PdfIndirectObjectReference => $value->value.' '.$value->generationNumber.' R',
            $value instanceof PdfName => '/'.$value->value,
            $value instanceof PdfString => '('.$value->value.')',
            $value instanceof PdfHexString => '<'.$value->value.'>',
            $value instanceof PdfNumeric => is_int($value->value)
                ? (string) $value->value
                : rtrim(rtrim(sprintf('%.5F', $value->value), '0'), '.'),
            $value instanceof PdfBoolean => $value->value ? 'true' : 'false',
            $value instanceof PdfNull => 'null',
            $value instanceof PdfToken => (string) $value->value,
            default => throw new SigningException('Cannot write a '.$value::class.' back into the PDF.'),
        };
    }
}
