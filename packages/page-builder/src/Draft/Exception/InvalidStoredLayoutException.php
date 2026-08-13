<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft\Exception;

/** @api */
final class InvalidStoredLayoutException extends \RuntimeException
{
    /** @param list<array{code: string, pointer: string, detail: string}> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Stored page layout is invalid and cannot be edited');
    }
}
