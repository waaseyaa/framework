<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Support\Http;

/**
 * The environment could not support the conformance harness.
 *
 * Every message is written to read as a skip reason: no resolvable PHP binary,
 * no free port, the server never becoming ready inside its bounded wait, a
 * socket that would not connect. A conformance test catches this and skips —
 * it never reports a missing precondition as a protocol defect.
 *
 * {@see $retryable} separates the one failure worth another go — losing an
 * allocated port to a racing process, which makes `php -S` exit at bind — from
 * every failure that a retry would only make slower.
 */
final class McpServerUnavailable extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
