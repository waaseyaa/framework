<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

/**
 * Reports the exact number of authorities committed before repair failed.
 *
 * @internal Restricted legacy-upgrade reporting only.
 */
final class MutationAuthorityBackfillException extends \RuntimeException
{
    public function __construct(
        public readonly int $committedCount,
        \Throwable $previous,
    ) {
        parent::__construct('Mutation-authority backfill failed.', 0, $previous);
    }
}
