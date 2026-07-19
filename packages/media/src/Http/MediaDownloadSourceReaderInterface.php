<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Http;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Media\Media;

/** Typed, field-selector-free reader for an already-authorized media download. @api */
interface MediaDownloadSourceReaderInterface
{
    public function sourceUri(Media $media, AccountInterface $account): string;
}
