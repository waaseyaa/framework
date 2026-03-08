<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Ingestion;

/**
 * Thrown when an ingestion envelope fails validation.
 *
 * Carries structured error details for operator diagnostics.
 * The canonical error shape will be defined in #249; this exception
 * provides the interim structured transport.
 */
final class InvalidEnvelopeException extends \RuntimeException
{
    /**
     * @param list<array{field: string, message: string}> $errors
     */
    public function __construct(
        public readonly array $errors,
        public readonly ?string $traceId = null,
        string $message = 'Envelope validation failed.',
    ) {
        parent::__construct($message);
    }
}
