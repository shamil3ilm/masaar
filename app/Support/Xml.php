<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;

/**
 * The one place untrusted XML gets parsed.
 *
 * Every DOMDocument::loadXML() call goes through here so three things are true
 * everywhere instead of per call site:
 *
 * 1. No DOCTYPE. PHP 8 with libxml >= 2.9 disables external entities by
 *    default, so classic XXE is not exploitable today — but that is implicit
 *    and one LIBXML_NOENT away from coming back, and entity expansion denial
 *    of service works regardless. No e-invoicing schema uses a DTD, so
 *    rejecting outright costs nothing.
 * 2. No network. LIBXML_NONET stops the parser resolving anything remote.
 * 3. Parse failures are visible. loadXML() returns false on bad input, and a
 *    caller that ignores it goes on to query an empty document — in the
 *    signing path that means signing something that is not the invoice.
 *
 * The caller passes its own DOMDocument on purpose. preserveWhiteSpace and the
 * constructor arguments change canonicalization, and therefore the bytes that
 * get hashed and signed. That choice belongs to the caller.
 */
final class Xml
{
    /**
     * Refuse anything bigger. Invoices are a few kilobytes; something this
     * size is a fault or an attack.
     */
    public const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Parse into the caller's document, or throw.
     *
     * Use this wherever a failed parse means the work cannot continue —
     * signing, hashing, QR insertion, submission.
     *
     * @throws XmlException
     */
    public static function load(
        DOMDocument $dom,
        string $xml,
        int $options = 0,
        int $maxBytes = self::MAX_BYTES,
    ): DOMDocument {
        [$loaded, $errors] = self::parse($dom, $xml, $options, $maxBytes);

        // Only a failed parse stops us. libxml also reports recoverable
        // problems on documents that load fine; treating those as fatal would
        // reject invoices that sign and clear today.
        if (! $loaded) {
            throw XmlException::cannotParse($errors);
        }

        return $dom;
    }

    /**
     * Parse into the caller's document and hand back the problems found.
     *
     * Only for validators, where collecting parse errors IS the result.
     *
     * @return list<string> Empty when the document parsed cleanly.
     */
    public static function errors(
        DOMDocument $dom,
        string $xml,
        int $options = 0,
        int $maxBytes = self::MAX_BYTES,
    ): array {
        return self::parse($dom, $xml, $options, $maxBytes)[1];
    }

    /**
     * @return array{0: bool, 1: list<string>} Whether it loaded, and what went wrong.
     */
    private static function parse(DOMDocument $dom, string $xml, int $options, int $maxBytes): array
    {
        $length = strlen($xml);

        if ($length === 0) {
            return [false, ['Document is empty.']];
        }

        if ($length > $maxBytes) {
            return [false, [XmlException::tooBig($length, $maxBytes)->getMessage()]];
        }

        if (self::hasDoctype($xml)) {
            return [false, [XmlException::hasDoctype()->getMessage()]];
        }

        // libxml error handling is global process state. Save the caller's
        // setting and put it back, so parsing here cannot change how unrelated
        // code sees libxml errors.
        $wasUsingInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $loaded = $dom->loadXML($xml, $options | LIBXML_NONET);
            $errors = self::describe(libxml_get_errors());

            if ($loaded === false && $errors === []) {
                $errors = ['Document could not be parsed.'];
            }

            return [$loaded !== false, $errors];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($wasUsingInternalErrors);
        }
    }

    /**
     * Whether the document declares a DTD.
     *
     * Checked on the raw bytes, because once libxml has built the document the
     * expansion has already happened. Only the prolog may hold a DOCTYPE, so a
     * bounded prefix is enough and avoids scanning megabytes for a literal.
     */
    private static function hasDoctype(string $xml): bool
    {
        return preg_match('/<!DOCTYPE/i', substr($xml, 0, 4096)) === 1;
    }

    /**
     * @param  array<int, \LibXMLError>  $errors
     * @return list<string>
     */
    private static function describe(array $errors): array
    {
        return array_values(array_map(
            static fn (\LibXMLError $error): string => sprintf(
                'line %d column %d: %s',
                $error->line,
                $error->column,
                trim($error->message)
            ),
            $errors
        ));
    }
}
