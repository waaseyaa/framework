<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

/** Fail-closed identity mismatch between an explicit query account and active principal. */
final class QueryAccountPrincipalMismatchException extends \RuntimeException
{
    public static function forBoundAccount(): self
    {
        return new self('The query account does not match the active immutable authorization principal.');
    }
}
