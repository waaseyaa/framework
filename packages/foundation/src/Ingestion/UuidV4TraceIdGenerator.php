<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Ingestion;

use Symfony\Component\Uid\Uuid;

/**
 * Default trace ID generator — produces a UUID v4 string.
 */
final class UuidV4TraceIdGenerator implements TraceIdGeneratorInterface
{
    public function generate(): string
    {
        return (string) Uuid::v4();
    }
}
