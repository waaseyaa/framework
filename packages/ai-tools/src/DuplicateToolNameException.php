<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

/** Raised when two registrations claim the same protocol-visible tool name. */
final class DuplicateToolNameException extends \LogicException
{
    public static function forName(string $name): self
    {
        return new self(\sprintf(
            'Agent tool name "%s" is already registered; duplicate names are refused.',
            $name,
        ));
    }
}
