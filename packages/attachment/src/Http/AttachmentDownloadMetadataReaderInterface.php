<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Http;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Attachment\Attachment;

/** Typed escape hatch for the authorized download surface; no field selector. @api */
interface AttachmentDownloadMetadataReaderInterface
{
    public function read(Attachment $attachment, AccountInterface $account): AttachmentDownloadMetadata;
}
