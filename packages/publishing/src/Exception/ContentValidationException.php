<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Exception;

/** @api */
final class ContentValidationException extends ContentPublishingException
{
    /** @param list<array{field: string, message: string}> $fieldErrors */
    public function __construct(array $fieldErrors)
    {
        parent::__construct('VALIDATION_FAILED', 'Content validation failed.', $fieldErrors);
    }
}
