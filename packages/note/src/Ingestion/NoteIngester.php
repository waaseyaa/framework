<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Ingestion;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Note\Note;

/**
 * Creates and persists a core.note entity from a validated ingestion envelope.
 * @api
 */
final class NoteIngester
{
    public function __construct(private readonly EntityRepositoryInterface $repository) {}

    public function ingest(IngestionEnvelope $envelope): Note
    {
        /** @var Note $note */
        $note = $this->repository->create([
            'title'            => $envelope->title,
            'body'             => $envelope->body,
            'ingestion_source' => $envelope->source,
            'ingested_at'      => $envelope->ingestedAt,
        ]);

        $this->repository->save($note);

        return $note;
    }
}
