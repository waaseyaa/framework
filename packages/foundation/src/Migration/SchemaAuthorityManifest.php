<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

/** Read-only manifest recorded atomically with an authoritative schema transition. */
final readonly class SchemaAuthorityManifest
{
    public function __construct(
        public ?string $schemaFingerprint,
        public ?string $ledgerFingerprint,
        public ?string $sourceCatalogFingerprint,
    ) {}
}
