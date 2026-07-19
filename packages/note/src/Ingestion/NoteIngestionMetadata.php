<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Ingestion;

/** Fixed-shape provenance emitted by the trusted note ingestion boundary. @api */
final readonly class NoteIngestionMetadata
{
    public function __construct(
        public string $source,
        public string $ingestedAt,
    ) {}
}
