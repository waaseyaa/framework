<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

/** Domain-separated identities derived from one validated sync artifact. @api */
final readonly class ConfigContentHashes
{
    /** @param array<string, mixed> $effectiveFields */
    public function __construct(
        public string $exactByteHash,
        public string $authoredContentHash,
        public string $effectiveEntryHash,
        public array $effectiveFields,
    ) {}
}
