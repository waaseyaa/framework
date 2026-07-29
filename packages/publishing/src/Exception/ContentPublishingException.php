<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Exception;

/**
 * Base class for every structured content-publishing failure.
 *
 * Carries a stable machine code (VALIDATION_FAILED, SLUG_TAKEN,
 * REVISION_CONFLICT, IDEMPOTENCY_CONFLICT, NOT_FOUND, UNAUTHORIZED) plus
 * optional field-specific errors, so transports (MCP tools, HTTP) can render
 * a faithful structured error without string-matching messages.
 *
 * @api
 */
abstract class ContentPublishingException extends \RuntimeException
{
    /**
     * @param list<array{field: string, message: string}> $fieldErrors
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $fieldErrors = [],
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
