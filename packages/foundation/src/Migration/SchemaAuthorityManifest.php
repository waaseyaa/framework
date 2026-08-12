<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

/** Read-only manifest recorded atomically with an authoritative schema transition. @api */
final readonly class SchemaAuthorityManifest
{
    public function __construct(
        public int $generation,
        public ?string $schemaFingerprint,
        public ?string $ledgerFingerprint,
        public ?string $sourceCatalogFingerprint,
    ) {}
}
