<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Internal non-sensitive marker for an owner adapter callback failure. */
final class ApplicationMasterAdapterExecutionException extends \RuntimeException
{
    /** @param class-string $failureClass */
    public function __construct(
        public readonly string $operation,
        public readonly string $failureClass,
    ) {
        parent::__construct('An application-master adapter operation failed.');
    }
}
