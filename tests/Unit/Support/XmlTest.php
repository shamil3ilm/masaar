<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Xml;
use App\Support\XmlException;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class XmlTest extends TestCase
{
    public function test_valid_xml_loads(): void
    {
        $dom = Xml::load(new DOMDocument(), '<Invoice><ID>INV-1</ID></Invoice>');

        $this->assertSame('Invoice', $dom->documentElement->nodeName);
        $this->assertSame('INV-1', $dom->getElementsByTagName('ID')->item(0)->nodeValue);
    }

    /**
     * The defect this replaces: loadXML() returns false and the caller carries
     * on querying an empty document, so signing produces a signature over
     * nothing instead of failing.
     */
    public function test_malformed_throws(): void
    {
        $this->expectException(XmlException::class);

        Xml::load(new DOMDocument(), '<Invoice><ID>unclosed</Invoice>');
    }

    public function test_empty_throws(): void
    {
        $this->expectException(XmlException::class);

        Xml::load(new DOMDocument(), '');
    }

    public function test_doctype_rejected(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE foo [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>
        <Invoice><ID>&xxe;</ID></Invoice>
        XML;

        $this->expectException(XmlException::class);
        $this->expectExceptionMessageMatches('/DOCTYPE/');

        Xml::load(new DOMDocument(), $xml);
    }

    /**
     * Billion laughs: the entity nesting is what makes this dangerous, and the
     * DOCTYPE rejection removes it before libxml expands anything.
     */
    public function test_entity_expansion_rejected(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE lolz [
          <!ENTITY lol "lol">
          <!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">
          <!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">
        ]>
        <lolz>&lol3;</lolz>
        XML;

        $this->expectException(XmlException::class);

        Xml::load(new DOMDocument(), $xml);
    }

    public function test_oversized_rejected(): void
    {
        $xml = '<Invoice>'.str_repeat('x', 2048).'</Invoice>';

        $this->expectException(XmlException::class);
        $this->expectExceptionMessageMatches('/exceeds the 1024 byte limit/');

        Xml::load(new DOMDocument(), $xml, maxBytes: 1024);
    }

    /**
     * The loader must not change the bytes a document serialises to.
     *
     * preserveWhiteSpace and the constructor arguments feed canonicalization,
     * which feeds the invoice hash and the signature. Any drift here silently
     * changes what gets signed, so this compares the loader's result against a
     * plain loadXML() on an identically-constructed document.
     *
     */
    #[DataProvider('documentConstructionProvider')]
    public function test_output_byte_identical(
        ?string $version,
        ?string $encoding,
        bool $preserveWhiteSpace,
    ): void {
        $xml = "<Invoice>\n    <ID>INV-1</ID>\n    <Total>100.00</Total>\n</Invoice>";

        $expected = $version === null ? new DOMDocument() : new DOMDocument($version, $encoding);
        $expected->preserveWhiteSpace = $preserveWhiteSpace;
        $expected->loadXML($xml);

        $actual = $version === null ? new DOMDocument() : new DOMDocument($version, $encoding);
        $actual->preserveWhiteSpace = $preserveWhiteSpace;
        Xml::load($actual, $xml);

        $this->assertSame($expected->saveXML(), $actual->saveXML());
        $this->assertSame($expected->C14N(), $actual->C14N());
    }

    public static function documentConstructionProvider(): array
    {
        return [
            'signer and hasher construction' => ['1.0', 'UTF-8', false],
            'bare construction' => [null, null, true],
            'bare, whitespace stripped' => [null, null, false],
        ];
    }

    public function test_errors_returns_diagnostics(): void
    {
        $errors = Xml::errors(new DOMDocument(), '<Invoice><ID>unclosed</Invoice>');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('line', $errors[0]);
    }

    public function test_errors_empty_when_valid(): void
    {
        $this->assertSame([], Xml::errors(new DOMDocument(), '<Invoice/>'));
    }

    /**
     * libxml's internal-error flag is global process state. Parsing must leave
     * it exactly as it was found, or unrelated code starts seeing (or missing)
     * errors depending on what parsed last.
     */
    public function test_libxml_state_restored(): void
    {
        $previous = libxml_use_internal_errors(false);

        try {
            Xml::errors(new DOMDocument(), '<Invoice><ID>unclosed</Invoice>');
            $this->assertFalse(libxml_use_internal_errors(false), 'internal error flag was left enabled');

            libxml_use_internal_errors(true);
            Xml::errors(new DOMDocument(), '<Invoice/>');
            $this->assertTrue(libxml_use_internal_errors(true), 'internal error flag was left disabled');
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
