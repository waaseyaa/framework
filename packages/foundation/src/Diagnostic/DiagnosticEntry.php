<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Diagnostic;

/**
 * Structured diagnostic log entry produced by DiagnosticEmitter.
 */
final class DiagnosticEntry
{
    public readonly string $remediation;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly DiagnosticCode $code,
        public readonly string $message,
        public readonly array $context,
    ) {
        $this->remediation = $code->remediation();
    }

    /**
     * @return array{code: string, message: string, context: array<string, mixed>, remediation: string}
     */
    public function toArray(): array
    {
        return [
            'code'        => $this->code->value,
            'message'     => $this->message,
            'context'     => $this->context,
            'remediation' => $this->remediation,
        ];
    }
}
