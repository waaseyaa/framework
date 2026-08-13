<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Exception;

/** @api */
final readonly class ManifestViolation
{
    public function __construct(
        public string $code,
        public string $path,
        public string $message,
    ) {}
}
