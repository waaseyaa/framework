<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Exception;

/**
 * Fail-closed refusal: acknowledgement receipts were supplied to a draft
 * surface that cannot carry them.
 *
 * Dropping the receipts would silently re-raise the advisory the caller has
 * already reviewed, so the call is refused before any write. The message is
 * deliberately free of receipts, policy detail, and implementation identity —
 * it reaches agent and HTTP transports verbatim.
 *
 * @api
 */
final class UnsupportedSaveAdvisoryAcknowledgementException extends ContentPublishingException
{
    public const string ERROR_CODE = 'SAVE_ADVISORY_UNSUPPORTED';

    public function __construct()
    {
        parent::__construct(
            self::ERROR_CODE,
            'This draft surface cannot accept save advisory acknowledgements.',
        );
    }
}
