<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Diagnostic;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Emits structured diagnostic log entries for operator-facing error codes.
 *
 * Each call to emit() writes a single-line JSON object via LoggerInterface
 * and returns the entry for callers that need to inspect or re-throw it.
 */
final class DiagnosticEmitter
{
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function emit(DiagnosticCode $code, string $message, array $context = []): DiagnosticEntry
    {
        $entry = new DiagnosticEntry($code, $message, $context);

        $this->logger->warning(json_encode($entry->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $entry;
    }
}
