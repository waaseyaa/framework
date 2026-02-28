<?php

declare(strict_types=1);

namespace Aurora\Access\Gate;

/**
 * Thrown when authorization fails in the Gate.
 */
final class AccessDeniedException extends \RuntimeException
{
    public function __construct(
        public readonly string $ability,
        public readonly mixed $subject,
        string $message = '',
    ) {
        $subjectType = is_object($subject) ? $subject::class : get_debug_type($subject);
        $defaultMessage = sprintf('Access denied for ability "%s" on subject of type "%s".', $ability, $subjectType);

        parent::__construct($message ?: $defaultMessage);
    }
}
