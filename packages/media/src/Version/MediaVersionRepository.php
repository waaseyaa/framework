<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Version;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * High-level repository for MediaVersion entities.
 *
 * Provides per-media version listing with optional per-account access filtering,
 * plus tip() and findByVid() lookups.
 *
 * IMPORTANT: Never uses getQuery(). All DB reads go through DatabaseInterface::select()
 * to keep bin/check-getquery-bindings baseline at zero new entries.
 *
 * @api
 */
final class MediaVersionRepository
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityRepositoryInterface $entityRepo,
        private readonly DatabaseInterface $db,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Return all versions for a media entity, newest first (vid DESC).
     *
     * Per-entity access filtering is the controller's responsibility
     * via EntityAccessHandler / the policy gate.
     *
     * @param AccountInterface|null $account Reserved for future per-version access filtering.
     * @return iterable<MediaVersion>
     */
    public function findVersionsForMedia(string $mediaUuid, ?AccountInterface $account = null): iterable
    {
        $rows = $this->db->select('media_version')
            ->condition('media_uuid', $mediaUuid)
            ->orderBy('vid', 'DESC')
            ->execute();

        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $version = $this->hydrateRow($row);
            if ($version === null) {
                continue;
            }

            yield $version;
        }
    }

    /**
     * Return the most recent MediaVersion for a media entity (vid = MAX).
     */
    public function tip(string $mediaUuid): ?MediaVersion
    {
        $rows = $this->db->select('media_version')
            ->condition('media_uuid', $mediaUuid)
            ->orderBy('vid', 'DESC')
            ->range(0, 1)
            ->execute();

        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            return $this->hydrateRow($row);
        }

        return null;
    }

    /**
     * Find a specific version by media UUID and version ID.
     */
    public function findByVid(string $mediaUuid, int $vid): ?MediaVersion
    {
        $rows = $this->db->select('media_version')
            ->condition('media_uuid', $mediaUuid)
            ->condition('vid', $vid)
            ->execute();

        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            return $this->hydrateRow($row);
        }

        return null;
    }

    /**
     * Return the next available vid for a given media_uuid (MAX(vid) + 1).
     *
     * Uses a raw query because DatabaseInterface::select() does not expose
     * aggregate expressions on the stable SelectInterface contract.
     *
     * Throws on query failure — historically a broken schema was swallowed
     * here and vid 1 returned, silently colliding with / rewriting version
     * history. Allocation is MAX+1 (not atomic); the caller pairs it with
     * the (media_uuid, vid) unique index and retries on
     * UniqueConstraintViolationException (see MediaVersionStorageDriver,
     * pattern per #1706).
     */
    public function nextVid(string $mediaUuid): int
    {
        $maxVid = 0;

        foreach (
            $this->db->query(
                'SELECT MAX(vid) AS max_vid FROM media_version WHERE media_uuid = ?',
                [$mediaUuid],
            ) as $row
        ) {
            /** @var array<string, mixed> $row */
            if (isset($row['max_vid'])) {
                $maxVid = (int) $row['max_vid'];
            }
            break;
        }

        return $maxVid + 1;
    }

    /**
     * Save a MediaVersion entity (new or update).
     */
    public function save(MediaVersion $version): void
    {
        $this->entityRepo->save($version);
    }

    /**
     * Delete all versions for a given media_uuid.
     * Used by cascade-delete on parent media removal.
     */
    public function deleteAllForMedia(string $mediaUuid): void
    {
        $rows = $this->db->select('media_version')
            ->condition('media_uuid', $mediaUuid)
            ->execute();

        $versions = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $version = $this->hydrateRow($row);
            if ($version !== null) {
                $versions[] = $version;
            }
        }

        foreach ($versions as $version) {
            try {
                $this->entityRepo->delete($version);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf('Failed to delete MediaVersion (uuid=%s): %s', $version->uuid(), $e->getMessage()),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRow(array $row): ?MediaVersion
    {
        if (!isset($row['id'])) {
            return null;
        }

        // Merge _data JSON blob if present (entity storage convention).
        if (isset($row['_data']) && is_string($row['_data'])) {
            try {
                $extra = json_decode($row['_data'], associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
                if (is_array($extra)) {
                    unset($row['_data']);
                    $row = array_merge($row, $extra);
                }
            } catch (\JsonException) {
                // Corrupt _data: proceed with what we have.
            }
        }

        $version = new MediaVersion($row);
        $version->enforceIsNew(false);

        return $version;
    }
}
