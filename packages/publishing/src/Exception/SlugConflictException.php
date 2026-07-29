<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Exception;

/** @api */
final class SlugConflictException extends ContentPublishingException
{
    public function __construct(string $slugField, string $slug)
    {
        parent::__construct(
            'SLUG_TAKEN',
            sprintf('The slug "%s" is already in use.', $slug),
            [['field' => $slugField, 'message' => sprintf('"%s" is already in use.', $slug)]],
        );
    }
}
