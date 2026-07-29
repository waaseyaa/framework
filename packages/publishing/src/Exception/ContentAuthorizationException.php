<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Exception;

/** @api */
final class ContentAuthorizationException extends ContentPublishingException
{
    public function __construct(string $message = 'Not authorized for this content operation.')
    {
        parent::__construct('UNAUTHORIZED', $message);
    }
}
