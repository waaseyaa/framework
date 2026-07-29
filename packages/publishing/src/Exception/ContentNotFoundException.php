<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Exception;

/** @api */
final class ContentNotFoundException extends ContentPublishingException
{
    public function __construct(string $idOrSlug)
    {
        parent::__construct('NOT_FOUND', sprintf('No content found for "%s".', $idOrSlug));
    }
}
