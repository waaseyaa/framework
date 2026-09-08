<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Exception;

/**
 * Raised after commit when one or more completion effects failed.
 *
 * When thrown from {@see \Waaseyaa\EntityStorage\UnitOfWork},
 * {@see self::committedByUnitToken()} identifies the outer
 * {@see \Waaseyaa\EntityStorage\UnitOfWork::transaction()} invocation whose
 * mutation committed. A bare completion exception without that token — or with
 * a foreign / prior-transaction token — is not proof that a different call
 * committed.
 *
 * @api
 */
final class TransactionCompletionException extends \RuntimeException
{
    /**
     * @param non-empty-list<\Throwable> $failures
     * @param string|null $committedByUnitToken Opaque UnitOfWork outer-transaction
     *        commitment token when this exception reports that specific call's
     *        post-commit drain failure.
     */
    public function __construct(
        private readonly array $failures,
        private readonly ?string $committedByUnitToken = null,
    ) {
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

    /**
     * Token of the outer {@see \Waaseyaa\EntityStorage\UnitOfWork::transaction()}
     * whose commit drained the failing completion work, or null when the
     * exception was built without that correlation (for example by a bare
     * database completion coordinator).
     */
    public function committedByUnitToken(): ?string
    {
        return $this->committedByUnitToken;
    }
}
