<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

use Waaseyaa\Database\Exception\TransactionCompletionException;

/**
 * Raised by {@see \Waaseyaa\EntityStorage\EntityRepository} after translating a
 * UnitOfWork post-commit {@see TransactionCompletionException} whose
 * {@see TransactionCompletionException::committedByUnitToken()} matches the
 * outer repository mutation's unit (#2999).
 *
 * {@see \Waaseyaa\EntityStorage\UnitOfWork} itself continues to throw bare
 * {@see TransactionCompletionException} (with the unit token stamped) so
 * existing `catch (TransactionCompletionException)` consumers keep working.
 * A completion failure from a different unit — for example an independent
 * nested repository write during PRE_SAVE / PRE_DELETE — is not translated.
 *
 * @api
 */
final class EntityMutationCommittedSideEffectsFailedException extends \RuntimeException
{
    public function __construct(
        private readonly TransactionCompletionException $completionFailure,
    ) {
        parent::__construct(
            'Entity mutation committed but required post-commit side effects failed.',
            previous: $completionFailure,
        );
    }

    public function completionFailure(): TransactionCompletionException
    {
        return $this->completionFailure;
    }
}
