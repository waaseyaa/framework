<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Ingestion;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Note\Note;

/** Closed reader for the two Internal ingestion-provenance values. @api */
final class NoteIngestionMetadataReader
{
    /** @var \Closure(Note): NoteIngestionMetadata */
    private readonly \Closure $obtain;

    public function __construct()
    {
        $this->obtain = \Closure::bind(
            static function (Note $note): NoteIngestionMetadata {
                $values = $note->valueContainer->rawValues();

                return new NoteIngestionMetadata(
                    (string) ($values['ingestion_source'] ?? ''),
                    (string) ($values['ingested_at'] ?? ''),
                );
            },
            null,
            EntityBase::class,
        );
    }

    public function read(Note $note): NoteIngestionMetadata
    {
        return ($this->obtain)($note);
    }
}
