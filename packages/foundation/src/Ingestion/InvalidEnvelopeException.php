<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Ingestion;

/**
 * Thrown when an ingestion envelope fails validation.
 *
 * Carries canonical IngestionError objects for operator diagnostics.
 */
final class InvalidEnvelopeException extends \RuntimeException
{
    /**
     * @param list<IngestionError> $errors
     */
    public function __construct(
        public readonly array $errors,
        public readonly ?string $traceId = null,
        string $message = 'Envelope validation failed.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<array{code: string, message: string, field: string, trace_id: ?string, details: array<string, mixed>}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn(IngestionError $error) => $error->toArray(),
            $this->errors,
        );
    }
}
