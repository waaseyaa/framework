<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Exception;

/** A legacy boolean storage mutation refused or failed. @api */
final class ConfigMutationFailedException extends \RuntimeException
{
    public static function forOperation(string $operation, string $name): self
    {
        return new self(sprintf('Configuration %s failed for "%s"; no success state was published.', $operation, $name));
    }
}
