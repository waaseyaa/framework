<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Exception;

use Waaseyaa\EntityStorage\Exception\SaveAdvisoryAcknowledgementRequiredException;

/** Structured publishing boundary for candidate-bound save advisories. @api */
final class ContentSaveAdvisoryException extends ContentPublishingException
{
    public function __construct(SaveAdvisoryAcknowledgementRequiredException $exception)
    {
        parent::__construct(
            'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
            $exception->getMessage(),
            meta: ['save_advisories' => $exception->toArray()],
        );
    }
}
