<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

/**
 * The live schema contains the named object but not in the declared shape.
 *
 * Raised instead of applying over it or skipping it, so an operator sees the
 * divergence rather than inheriting a database that disagrees with its ledger.
 *
 * @see docs/change-records/FW-2701.md — C4 fail closed
 */
final class IncompatibleSchemaStateException extends \RuntimeException
{
    public static function column(string $table, string $column, string $detail): self
    {
        return new self(sprintf(
            '[S1-DB110] Column "%s"."%s" exists but does not match the declared operation: %s. '
            . 'Migration refused; schema and ledger are unchanged.',
            $table,
            $column,
            $detail,
        ));
    }

    public static function index(string $table, string $index, string $detail): self
    {
        return new self(sprintf(
            '[S1-DB111] Index "%s" on "%s" exists but does not match the declared operation: %s. '
            . 'Migration refused; schema and ledger are unchanged.',
            $index,
            $table,
            $detail,
        ));
    }
}
