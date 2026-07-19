<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

/** Fail-closed compilation/execution failure for a closed policy projection. */
final class ProtectedEntityReadProjectionException extends \RuntimeException
{
    public static function cannotCompile(string $entityTypeId, string $reason): self
    {
        return new self(sprintf(
            'Cannot compile the Protected entity-read projection for "%s": %s',
            $entityTypeId,
            $reason,
        ));
    }

    public static function incompleteRow(string $entityTypeId): self
    {
        return new self(sprintf(
            'A Protected entity-read projection row for "%s" was incomplete; access evaluation stopped fail-closed.',
            $entityTypeId,
        ));
    }
}
