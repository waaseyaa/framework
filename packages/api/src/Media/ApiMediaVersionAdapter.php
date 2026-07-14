<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Media;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Media\Version\MediaVersion;
use Waaseyaa\Media\Version\MediaVersionRepository;

/**
 * Bridges MediaVersionRepository (L2 media) to the API read-model (L4).
 *
 * @internal Parked until #1742's byte-persistence criterion is met.
 *
 * Applies per-version access filtering via the GateInterface so that
 * inaccessible versions are silently omitted from list responses and
 * return null on single-version lookups (controller maps null → 403/404).
 *
 * The api → media import direction is L4 → L2 (downward) — allowed.
 *
 * Refs DIR-005 (versioned-blob-media-abstraction-01KSEFTJ).
 */
final class ApiMediaVersionAdapter implements MediaVersionReadModelInterface
{
    public function __construct(
        private readonly MediaVersionRepository $repo,
        private readonly ?GateInterface $gate = null,
    ) {}

    public function findForMedia(string $mediaUuid, AccountInterface $account): iterable
    {
        foreach ($this->repo->findVersionsForMedia($mediaUuid) as $version) {
            if ($this->isAllowed($version, $account)) {
                yield $this->toResource($version);
            }
        }
    }

    public function findByVid(string $mediaUuid, int $vid, AccountInterface $account): ?MediaVersionResource
    {
        $version = $this->repo->findByVid($mediaUuid, $vid);
        if ($version === null) {
            return null;
        }

        if (!$this->isAllowed($version, $account)) {
            return null;
        }

        return $this->toResource($version);
    }

    public function existsByVid(string $mediaUuid, int $vid): bool
    {
        return $this->repo->findByVid($mediaUuid, $vid) !== null;
    }

    private function isAllowed(MediaVersion $version, AccountInterface $account): bool
    {
        if ($this->gate === null) {
            return true;
        }

        return $this->gate->allows('view', $version, $account);
    }

    private function toResource(MediaVersion $version): MediaVersionResource
    {
        return new MediaVersionResource(
            vid: $version->vid(),
            mediaUuid: $version->mediaUuid(),
            blobUri: $version->blobUri(),
            mime: $version->mime(),
            sizeBytes: $version->size(),
            sha256: $version->sha256(),
            createdAt: $version->createdAt(),
            createdBy: $version->createdBy(),
        );
    }
}
