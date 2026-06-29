<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Ingestion;

/**
 * Generates a trace ID string for ingestion envelope normalization.
 *
 * Implement this interface to substitute the default UUID v4 strategy with a
 * custom generator (e.g. a deterministic ID for tests, a distributed-tracing
 * propagation header, or a custom format).
 *
 * @api
 */
interface TraceIdGeneratorInterface
{
    /**
     * Generate and return a new trace ID string.
     */
    public function generate(): string;
}
