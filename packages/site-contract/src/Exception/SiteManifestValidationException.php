<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Exception;

/** @api */
final class SiteManifestValidationException extends \InvalidArgumentException
{
    /** @param non-empty-list<ManifestViolation> $violations */
    public function __construct(
        public readonly string $source,
        public readonly array $violations,
        ?\Throwable $previous = null,
    ) {
        $first = $violations[0];
        parent::__construct(sprintf('%s %s at %s: %s', $source, $first->code, $first->path, $first->message), 0, $previous);
    }
}
