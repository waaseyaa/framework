<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Ingestion;

use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Note\Note;

/**
 * Creates and persists a core.note entity from a validated ingestion envelope.
 */
final class NoteIngester
{
    public function __construct(private readonly EntityStorageInterface $storage) {}

    public function ingest(IngestionEnvelope $envelope): Note
    {
        /** @var Note $note */
        $note = $this->storage->create([
            'title'            => $envelope->title,
            'body'             => $envelope->body,
            'ingestion_source' => $envelope->source,
            'ingested_at'      => $envelope->ingestedAt,
        ]);

        $this->storage->save($note);

        return $note;
    }
}
