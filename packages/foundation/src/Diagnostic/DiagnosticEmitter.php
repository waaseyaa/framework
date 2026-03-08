<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Diagnostic;

/**
 * Emits structured diagnostic log entries for operator-facing error codes.
 *
 * Each call to emit() writes a single-line JSON object to error_log()
 * (following the project's no-psr/log convention) and returns the entry
 * for callers that need to inspect or re-throw it.
 */
final class DiagnosticEmitter
{
    /**
     * @param array<string, mixed> $context
     */
    public function emit(DiagnosticCode $code, string $message, array $context = []): DiagnosticEntry
    {
        $entry = new DiagnosticEntry($code, $message, $context);

        error_log(json_encode($entry->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $entry;
    }
}
