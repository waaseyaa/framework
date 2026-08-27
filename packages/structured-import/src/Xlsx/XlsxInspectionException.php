<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Xlsx;

/**
 * Value- and path-free XLSX refusal.
 *
 * @api
 */
final class XlsxInspectionException extends \RuntimeException
{
    public function __construct(public readonly XlsxInspectionError $reason)
    {
        parent::__construct('XLSX inspection refused: ' . $reason->value . '.');
    }
}
