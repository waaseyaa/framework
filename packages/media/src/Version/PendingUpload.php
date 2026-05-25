<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Version;

/**
 * Transient value object attached to a Media entity to signal that a new
 * blob upload should be versioned on POST_SAVE.
 *
 * Set via MediaVersionStorageDriver::setPendingUpload() before saving the
 * parent Media entity. Cleared by the driver after processing.
 *
 * @api
 */
final readonly class PendingUpload
{
    /**
     * @param string $bytes    Raw blob content.
     * @param string $mime     MIME type as declared at upload time.
     * @param int    $accountUid UID of the account performing the upload (0 = anonymous).
     */
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly int $accountUid,
    ) {}
}
