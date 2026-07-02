<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Version;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Media\Media;

/**
 * Subscribes to Media POST_SAVE events and creates a new MediaVersion per upload.
 *
 * Integration point: before calling EntityRepository::save() on a Media entity,
 * callers attach a PendingUpload via MediaVersionStorageDriver::setPendingUpload().
 * After save this driver picks it up, writes it to the CAS store, creates a
 * MediaVersion row, and emits an audit event. Non-upload saves are a no-op (C-001).
 *
 * Best-effort: the entire body is wrapped in try-catch; upload-version failures
 * MUST NOT disrupt the parent save (per CLAUDE.md §Logging best-effort pattern).
 *
 * Refs DIR-005 — extension of the media-entity surface, not a replacement.
 *
 * @api
 */
final class MediaVersionStorageDriver implements EventSubscriberInterface
{
    /**
     * Bounded retries for the MAX+1 vid allocation race: the
     * (media_uuid, vid) unique index rejects a concurrent duplicate and we
     * re-read MAX rather than surfacing it (pattern per #1706,
     * RevisionableStorageDriver). Single-threaded, the loop runs exactly once.
     */
    private const int MAX_VID_ALLOCATION_ATTEMPTS = 5;

    private readonly LoggerInterface $logger;

    /**
     * Keyed by media UUID → PendingUpload.
     *
     * @var array<string, PendingUpload>
     */
    private array $pendingUploads = [];

    public function __construct(
        private readonly MediaVersionRepository $versionRepo,
        private readonly ContentAddressedFileRepositoryDecorator $cas,
        private readonly AuditWriterInterface $auditWriter,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::POST_SAVE->value => 'onMediaPostSave',
        ];
    }

    /**
     * Register a pending upload for the next save of the given media UUID.
     *
     * Must be called BEFORE EntityRepository::save() on the parent Media entity.
     */
    public function setPendingUpload(string $mediaUuid, PendingUpload $upload): void
    {
        $this->pendingUploads[$mediaUuid] = $upload;
    }

    /**
     * Called on waaseyaa.entity.post_save for every entity type.
     * Guards early-return for non-Media entities and no-upload saves (C-001).
     */
    public function onMediaPostSave(EntityEvent $event): void
    {
        try {
            $entity = $event->entity;

            if (!$entity instanceof Media) {
                return;
            }

            $mediaUuid = $entity->uuid();
            if ($mediaUuid === '') {
                return;
            }

            $pending = $this->pendingUploads[$mediaUuid] ?? null;
            if ($pending === null) {
                // C-001: metadata-only save — no new upload.
                return;
            }

            // Consume the pending upload (single-shot).
            unset($this->pendingUploads[$mediaUuid]);

            $this->createVersion($mediaUuid, $pending);
        } catch (\Throwable $e) {
            $this->logger->warning('MediaVersionStorageDriver.onMediaPostSave failed: ' . $e->getMessage());
        }
    }

    private function createVersion(string $mediaUuid, PendingUpload $pending): void
    {
        // 1. Write blob to CAS (deduplicates by sha256).
        $result = $this->cas->write($pending->bytes, $pending->mime);

        // 2+3. Allocate the next vid and insert (race-safe, bounded retry).
        $nextVid = $this->saveVersionWithAllocatedVid($mediaUuid, $pending, $result);

        // 4. Emit audit events.
        $this->auditWriter->record(new AuditEventDescriptor(
            kind: AuditEventKind::MediaVersionCreated,
            accountUid: $pending->accountUid,
            subjectUri: sprintf('/media/%s/versions/%d', $mediaUuid, $nextVid),
            outcome: 'allowed',
            severity: 'info',
            entityTypeId: 'media',
            entityUuid: $mediaUuid,
            attributes: [
                'vid'       => $nextVid,
                'sha256'    => $result->sha256,
                'mime'      => $result->mime,
                'size_bytes' => $result->sizeBytes,
                'dedup_hit' => $result->dedupHit,
            ],
        ));

        if ($result->dedupHit) {
            $this->auditWriter->record(new AuditEventDescriptor(
                kind: AuditEventKind::MediaVersionDedupHit,
                accountUid: $pending->accountUid,
                subjectUri: sprintf('/media/%s/versions/%d', $mediaUuid, $nextVid),
                outcome: 'allowed',
                severity: 'info',
                entityTypeId: 'media',
                entityUuid: $mediaUuid,
                attributes: [
                    'sha256'  => $result->sha256,
                    'blob_uri' => $result->blobUri,
                ],
            ));
        }
    }

    /**
     * Allocate the next vid and insert the version row, retrying past races.
     *
     * nextVid() is MAX+1, not atomic; the (media_uuid, vid) unique index
     * rejects a concurrent duplicate and we retry with a freshly re-read MAX
     * (and a fresh entity) rather than dropping the version or duplicating
     * history.
     *
     * @return int The vid the version row was saved with.
     */
    private function saveVersionWithAllocatedVid(
        string $mediaUuid,
        PendingUpload $pending,
        FileWriteResult $result,
    ): int {
        for ($attempt = 1; ; $attempt++) {
            $nextVid = $this->versionRepo->nextVid($mediaUuid);

            $version = new MediaVersion([
                'uuid'       => (string) Uuid::v4(),
                'media_uuid' => $mediaUuid,
                'vid'        => $nextVid,
                'blob_uri'   => $result->blobUri,
                'mime'       => $result->mime,
                'size'       => $result->sizeBytes,
                'sha256'     => $result->sha256,
                'created_at' => time(),
                'created_by' => $pending->accountUid,
            ]);
            $version->enforceIsNew(true);

            try {
                $this->versionRepo->save($version);

                return $nextVid;
            } catch (UniqueConstraintViolationException $e) {
                // A concurrent writer claimed this (media_uuid, vid).
                if ($attempt >= self::MAX_VID_ALLOCATION_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}
