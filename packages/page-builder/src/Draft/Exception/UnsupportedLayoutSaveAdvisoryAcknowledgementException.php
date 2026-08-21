<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft\Exception;

/**
 * Fail-closed refusal: acknowledgement receipts were supplied to a layout-draft
 * gateway that cannot carry them.
 *
 * Dropping the receipts would silently re-raise the advisory the caller has
 * already reviewed, so the call is refused before any write. The message is
 * deliberately free of receipts, policy detail, and implementation identity —
 * it reaches agent and HTTP transports verbatim.
 *
 * A gateway raises this whether the refusal originates in the layout seam or in
 * a mutation seam further in. A publishing-backed gateway translates
 * `Waaseyaa\Publishing\Exception\UnsupportedSaveAdvisoryAcknowledgementException`
 * into this, because a page-builder transport catches only the layout contract:
 * `waaseyaa/admin-surface` has no dependency on `waaseyaa/publishing`, so an
 * untranslated refusal escapes the host uncaught and the structured
 * `501 SAVE_ADVISORY_UNSUPPORTED` never reaches the client. The cause is chained
 * for diagnosis only; nothing from it reaches a transport payload.
 *
 * @api
 */
final class UnsupportedLayoutSaveAdvisoryAcknowledgementException extends \RuntimeException
{
    public const string ERROR_CODE = 'SAVE_ADVISORY_UNSUPPORTED';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'This layout draft surface cannot accept save advisory acknowledgements.',
            previous: $previous,
        );
    }
}
