<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Version;

use Waaseyaa\Media\File;
use Waaseyaa\Media\FileRepositoryInterface;

/**
 * Wraps a FileRepositoryInterface to provide content-addressed (CAS) writes.
 *
 * URI scheme: `cas://<sha256>` — deterministic, collision-free, deduplicating.
 * Identical blobs (same SHA-256) resolve to the same URI and are not re-stored.
 *
 * The decorator is purely additive — all FileRepositoryInterface methods
 * delegate to the inner repository unchanged. Only `write()` is new surface.
 *
 * Refs DIR-005 — extends, does not replace, the existing file-repository surface.
 *
 * @internal Parked until #1742's byte-persistence criterion is met.
 */
final class ContentAddressedFileRepositoryDecorator implements FileRepositoryInterface
{
    public function __construct(
        private readonly FileRepositoryInterface $inner,
    ) {}

    /**
     * Write raw blob bytes into the CAS store.
     *
     * Derives the sha256 from `$bytes`, constructs the deterministic URI, and
     * checks whether the inner repository already holds a file at that URI
     * (dedup). Only writes to the inner store on a miss.
     *
     * @param string $bytes Raw blob content.
     * @param string $mime  Declared MIME type.
     */
    public function write(string $bytes, string $mime): FileWriteResult
    {
        $sha256 = hash('sha256', $bytes);
        $blobUri = 'cas://' . $sha256;
        $sizeBytes = strlen($bytes);

        $existing = $this->inner->load($blobUri);
        $dedupHit = $existing !== null;

        if (!$dedupHit) {
            $file = new File(
                uri: $blobUri,
                filename: $sha256,
                mimeType: $mime,
                size: $sizeBytes,
                ownerId: null,
                createdTime: time(),
            );
            $this->inner->save($file);
        }

        return new FileWriteResult(
            blobUri: $blobUri,
            sha256: $sha256,
            sizeBytes: $sizeBytes,
            mime: $mime,
            dedupHit: $dedupHit,
        );
    }

    // --- FileRepositoryInterface delegation ---

    public function save(File $file): File
    {
        return $this->inner->save($file);
    }

    public function load(string $uri): ?File
    {
        return $this->inner->load($uri);
    }

    public function delete(string $uri): bool
    {
        return $this->inner->delete($uri);
    }

    /**
     * @return File[]
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->inner->findByOwner($ownerId);
    }
}
