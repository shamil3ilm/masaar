<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Contracts;

/**
 * What a jurisdiction's seal produced.
 *
 * $columns are saved on the invoice in the same update that makes it Issued,
 * so the document and the status commit as one write. $memo is anything the
 * jurisdiction needs again once that write is done - for ZATCA, the
 * predecessor's hash the chain entry names, read before the document was
 * built.
 */
final readonly class Seal
{
    /**
     * @param  array<string, mixed>  $columns
     */
    public function __construct(
        public array $columns = [],
        public mixed $memo = null,
    ) {}
}
