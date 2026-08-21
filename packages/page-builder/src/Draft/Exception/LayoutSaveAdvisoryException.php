<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft\Exception;

/**
 * Typed no-write outcome carrying the advisories a layout edit must review.
 *
 * The layout seam owns its own advisory signal so a page-builder transport can
 * present the review without depending on whichever mutation service backs the
 * gateway. A publishing-backed gateway translates
 * `Waaseyaa\Publishing\Exception\ContentSaveAdvisoryException` into this, the
 * same way it already translates authorization and not-found outcomes.
 *
 * Payloads are the framework advisory shape verbatim: code, field, severity,
 * message, and the candidate-bound acknowledgement the caller returns to retry.
 *
 * @api
 */
final class LayoutSaveAdvisoryException extends \RuntimeException
{
    public const string ERROR_CODE = 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED';

    /** @var non-empty-list<array<string, mixed>> */
    private readonly array $payloads;

    /** @param array<int, mixed> $advisoryPayloads */
    public function __construct(array $advisoryPayloads, ?\Throwable $previous = null)
    {
        if ($advisoryPayloads === [] || !array_is_list($advisoryPayloads)) {
            throw new \InvalidArgumentException('At least one advisory payload is required.');
        }
        foreach ($advisoryPayloads as $payload) {
            if (!is_array($payload)) {
                throw new \InvalidArgumentException('Layout save advisories require array payloads.');
            }
        }

        /** @var non-empty-list<array<string, mixed>> $advisoryPayloads */
        $this->payloads = $advisoryPayloads;

        parent::__construct('Review and acknowledge the save advisory before retrying.', previous: $previous);
    }

    /** @return non-empty-list<array<string, mixed>> */
    public function advisoryPayloads(): array
    {
        return $this->payloads;
    }
}
