<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Http;

/** Fixed metadata required after an attachment download is authorized. @api */
final readonly class AttachmentDownloadMetadata
{
    public function __construct(
        public string $storageUri,
        public string $contentType,
        public string $filename,
    ) {}
}
