<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Version;

/**
 * Value object returned by ContentAddressedFileRepositoryDecorator::write().
 *
 * @internal Parked until #1742's byte-persistence criterion is met.
 */
final readonly class FileWriteResult
{
    /**
     * @param string $blobUri   Content-addressed URI, e.g. `cas://<sha256>`.
     * @param string $sha256    Hex-encoded SHA-256 of the raw blob bytes.
     * @param int    $sizeBytes Byte length of the stored blob.
     * @param string $mime      MIME type recorded at write time.
     * @param bool   $dedupHit  TRUE when an existing blob with the same sha256 was reused.
     */
    public function __construct(
        public readonly string $blobUri,
        public readonly string $sha256,
        public readonly int $sizeBytes,
        public readonly string $mime,
        public readonly bool $dedupHit,
    ) {}
}
