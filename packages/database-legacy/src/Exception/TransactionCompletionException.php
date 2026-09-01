<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Exception;

/** Raised after commit when one or more completion effects failed. @api */
final class TransactionCompletionException extends \RuntimeException
{
    /** @param non-empty-list<\Throwable> $failures */
    public function __construct(private readonly array $failures)
    {
        parent::__construct(
            sprintf('%d transaction completion effect(s) failed after commit.', count($failures)),
            previous: $failures[0],
        );
    }

    /** @return non-empty-list<\Throwable> */
    public function failures(): array
    {
        return $this->failures;
    }
}
