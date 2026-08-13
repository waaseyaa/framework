<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Editor\Exception;

/** @api */
final class InvalidEditCommandException extends \RuntimeException
{
    /**
     * @param list<array{code: string, pointer: string, detail: string}> $violations
     */
    public function __construct(
        public readonly string $machineCode,
        string $message,
        public readonly array $violations = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
